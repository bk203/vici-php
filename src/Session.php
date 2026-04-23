<?php

declare(strict_types=1);

namespace Bk203\Vici;

use Bk203\Vici\Exception\CommandException;
use Bk203\Vici\Exception\CommandUnknownException;
use Bk203\Vici\Exception\EventRegistrationException;
use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Message\MessageDecoder;
use Bk203\Vici\Message\MessageEncoder;
use Bk203\Vici\Protocol\Packet;
use Bk203\Vici\Protocol\PacketCodec;
use Bk203\Vici\Protocol\PacketType;
use Bk203\Vici\Transport\TransportInterface;
use Bk203\Vici\Transport\UnixSocketTransport;
use Generator;

/**
 * High-level VICI client session.
 *
 * Exposes typed wrappers for every command documented in the VICI protocol
 * README. Commands that the daemon answers via a stream of events (e.g.
 * `list-sas`) are returned as {@see Generator}s so callers can break early
 * without buffering the whole result.
 */
final class Session
{
    private readonly PacketCodec $packetCodec;
    private readonly MessageEncoder $messageEncoder;
    private readonly MessageDecoder $messageDecoder;

    /** @var array<string, list<callable(string $name, array<string, mixed> $message): void>> */
    private array $eventHandlers = [];

    /**
     * Events that have been registered on the current connection. Used so
     * nested streaming calls / external listeners can share event traffic
     * without double-registering.
     *
     * @var array<string, int>
     */
    private array $registrationRefcount = [];

    public function __construct(
        private readonly TransportInterface $transport = new UnixSocketTransport(),
    ) {
        $this->packetCodec = new PacketCodec();
        $this->messageEncoder = new MessageEncoder();
        $this->messageDecoder = new MessageDecoder();
    }

    public function transport(): TransportInterface
    {
        return $this->transport;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    // ---------------------------------------------------------------------
    // Event handler registration (client-side dispatch, no daemon traffic).
    // ---------------------------------------------------------------------

    /**
     * @param callable(string $name, array<string, mixed> $message): void $handler
     */
    public function onEvent(string $event, callable $handler): void
    {
        $this->eventHandlers[$event][] = $handler;
    }

    /**
     * Remove all handlers registered for $event (local dispatch only).
     */
    public function offEvent(string $event): void
    {
        unset($this->eventHandlers[$event]);
    }

    // ---------------------------------------------------------------------
    // Event (de-)registration with the daemon.
    // ---------------------------------------------------------------------

    /**
     * Register interest in a server event with the daemon. Calls are
     * reference-counted so nested users (e.g. streaming commands) stay safe.
     */
    public function registerEvent(string $event): void
    {
        if (isset($this->registrationRefcount[$event])) {
            $this->registrationRefcount[$event]++;
            return;
        }

        $this->writePacket(Packet::eventRegister($event));

        $reply = $this->readUntilControlPacket([
            PacketType::EVENT_CONFIRM,
            PacketType::EVENT_UNKNOWN,
        ]);

        if ($reply->type === PacketType::EVENT_UNKNOWN) {
            throw new EventRegistrationException(
                \sprintf('VICI daemon does not know event "%s".', $event),
                $event,
            );
        }

        $this->registrationRefcount[$event] = 1;
    }

    public function unregisterEvent(string $event): void
    {
        if (!isset($this->registrationRefcount[$event])) {
            return;
        }
        if (--$this->registrationRefcount[$event] > 0) {
            return;
        }
        unset($this->registrationRefcount[$event]);

        $this->writePacket(Packet::eventUnregister($event));
        $this->readUntilControlPacket([
            PacketType::EVENT_CONFIRM,
            PacketType::EVENT_UNKNOWN,
        ]);
    }

    // ---------------------------------------------------------------------
    // Request / response.
    // ---------------------------------------------------------------------

    /**
     * Send a command to the daemon and return its decoded response message.
     *
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function request(string $command, array $message = []): array
    {
        $payload = $this->messageEncoder->encode($message);
        $this->writePacket(Packet::cmdRequest($command, $payload));

        $reply = $this->readUntilControlPacket([
            PacketType::CMD_RESPONSE,
            PacketType::CMD_UNKNOWN,
        ]);

        if ($reply->type === PacketType::CMD_UNKNOWN) {
            throw new CommandUnknownException(\sprintf(
                'VICI daemon does not implement command "%s".',
                $command,
            ));
        }

        return $this->messageDecoder->decode($reply->payload);
    }

    /**
     * Request variant that calls {@see CommandException} when the response
     * indicates `success = no`. Returns the (successful) response otherwise.
     *
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function requireSuccess(string $command, array $message = []): array
    {
        $response = $this->request($command, $message);
        if (isset($response['success']) && $response['success'] !== 'yes') {
            $errmsg = \is_string($response['errmsg'] ?? null)
                ? $response['errmsg']
                : \sprintf('VICI command "%s" failed.', $command);
            /** @var array<string, mixed> $response */
            throw new CommandException($errmsg, $command, $response);
        }

        return $response;
    }

    /**
     * Send a command that streams intermediate results via $streamEvent and
     * yield each decoded event message until the command response arrives.
     *
     * The generator guarantees event (de-)registration is balanced even when
     * the caller abandons it early. On early abort the remaining stream
     * events and terminating CMD_RESPONSE are drained from the transport
     * before EVENT_UNREGISTER is issued, so subsequent commands on the same
     * connection stay in sync.
     *
     * @param array<array-key, mixed> $message
     * @return Generator<int, array<string, mixed>>
     */
    public function streamedRequest(string $command, string $streamEvent, array $message = []): Generator
    {
        $this->registerEvent($streamEvent);

        $commandCompleted = false;
        try {
            $payload = $this->messageEncoder->encode($message);
            $this->writePacket(Packet::cmdRequest($command, $payload));

            while (true) {
                $packet = $this->readPacket();

                if ($packet->type === PacketType::EVENT) {
                    /** @var string $evName */
                    $evName = $packet->name;
                    $decoded = $packet->payload === ''
                        ? []
                        : $this->messageDecoder->decode($packet->payload);

                    if ($evName === $streamEvent) {
                        yield $decoded;
                        continue;
                    }

                    $this->dispatchEvent($evName, $decoded);
                    continue;
                }

                if ($packet->type === PacketType::CMD_RESPONSE) {
                    $commandCompleted = true;
                    return;
                }
                if ($packet->type === PacketType::CMD_UNKNOWN) {
                    $commandCompleted = true;
                    throw new CommandUnknownException(\sprintf(
                        'VICI daemon does not implement command "%s".',
                        $command,
                    ));
                }

                throw new ProtocolException(\sprintf(
                    'Unexpected packet %s while awaiting response for command "%s".',
                    $packet->type->name,
                    $command,
                ));
            }
        } finally {
            if (!$commandCompleted) {
                $this->drainStreamRemainder($streamEvent);
            }
            $this->unregisterEvent($streamEvent);
        }
    }

    /**
     * Read and discard remaining stream events until CMD_RESPONSE/CMD_UNKNOWN
     * arrives. Non-stream events are still dispatched to registered handlers.
     */
    private function drainStreamRemainder(string $streamEvent): void
    {
        while (true) {
            $packet = $this->readPacket();
            if (
                $packet->type === PacketType::CMD_RESPONSE
                || $packet->type === PacketType::CMD_UNKNOWN
            ) {
                return;
            }
            if ($packet->type !== PacketType::EVENT) {
                throw new ProtocolException(\sprintf(
                    'Unexpected packet %s while draining stream "%s".',
                    $packet->type->name,
                    $streamEvent,
                ));
            }
            /** @var string $evName */
            $evName = $packet->name;
            if ($evName === $streamEvent) {
                continue;
            }
            $decoded = $packet->payload === ''
                ? []
                : $this->messageDecoder->decode($packet->payload);
            $this->dispatchEvent($evName, $decoded);
        }
    }

    /**
     * Drain any already-queued events from the transport and dispatch them.
     * Useful when combining a Session with a long-running EventListener.
     */
    public function pumpEvents(float $timeout = 0.0): void
    {
        while ($this->transport->hasData($timeout)) {
            $packet = $this->readPacket();
            if ($packet->type !== PacketType::EVENT) {
                throw new ProtocolException(\sprintf(
                    'Unexpected packet %s while pumping events (idle).',
                    $packet->type->name,
                ));
            }
            /** @var string $evName */
            $evName = $packet->name;
            $decoded = $packet->payload === ''
                ? []
                : $this->messageDecoder->decode($packet->payload);
            $this->dispatchEvent($evName, $decoded);
            $timeout = 0.0;
        }
    }

    // ---------------------------------------------------------------------
    // Internal I/O helpers.
    // ---------------------------------------------------------------------

    /**
     * Read packets from the transport, dispatching any EVENT packets to the
     * registered handlers, until a packet of one of the expected types
     * arrives.
     *
     * @param list<PacketType> $expected
     */
    private function readUntilControlPacket(array $expected): Packet
    {
        while (true) {
            $packet = $this->readPacket();

            if (\in_array($packet->type, $expected, true)) {
                return $packet;
            }

            if ($packet->type === PacketType::EVENT) {
                /** @var string $evName */
                $evName = $packet->name;
                $decoded = $packet->payload === ''
                    ? []
                    : $this->messageDecoder->decode($packet->payload);
                $this->dispatchEvent($evName, $decoded);
                continue;
            }

            throw new ProtocolException(\sprintf(
                'Unexpected packet %s; expected %s.',
                $packet->type->name,
                implode('|', array_map(static fn (PacketType $t): string => $t->name, $expected)),
            ));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function dispatchEvent(string $event, array $message): void
    {
        foreach ($this->eventHandlers[$event] ?? [] as $handler) {
            $handler($event, $message);
        }
    }

    private function writePacket(Packet $packet): void
    {
        $this->transport->send($this->packetCodec->encode($packet));
    }

    private function readPacket(): Packet
    {
        $bytes = $this->transport->receive();
        return $this->packetCodec->decode($bytes);
    }

    // =====================================================================
    // Typed command wrappers. These mirror the commands documented in
    // strongSwan's VICI README, in the order they appear there.
    // =====================================================================

    /** @return array<string, mixed> */
    public function version(): array
    {
        return $this->request('version');
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->request('stats');
    }

    /** @return array<string, mixed> */
    public function reloadSettings(): array
    {
        return $this->requireSuccess('reload-settings');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function initiate(array $message): array
    {
        return $this->requireSuccess('initiate', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function terminate(array $message): array
    {
        return $this->requireSuccess('terminate', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function rekey(array $message): array
    {
        return $this->requireSuccess('rekey', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function redirect(array $message): array
    {
        return $this->requireSuccess('redirect', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function install(array $message): array
    {
        return $this->requireSuccess('install', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function uninstall(array $message): array
    {
        return $this->requireSuccess('uninstall', $message);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listSas(array $filter = []): Generator
    {
        return $this->streamedRequest('list-sas', Event::LIST_SA, $filter);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listPolicies(array $filter = []): Generator
    {
        return $this->streamedRequest('list-policies', Event::LIST_POLICY, $filter);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listConns(array $filter = []): Generator
    {
        return $this->streamedRequest('list-conns', Event::LIST_CONN, $filter);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listCerts(array $filter = []): Generator
    {
        return $this->streamedRequest('list-certs', Event::LIST_CERT, $filter);
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return Generator<int, array<string, mixed>>
     */
    public function listAuthorities(array $filter = []): Generator
    {
        return $this->streamedRequest('list-authorities', Event::LIST_AUTHORITY, $filter);
    }

    /** @return array<string, mixed> */
    public function getConns(): array
    {
        return $this->request('get-conns');
    }

    /** @return array<string, mixed> */
    public function getAuthorities(): array
    {
        return $this->request('get-authorities');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadConn(array $message): array
    {
        return $this->requireSuccess('load-conn', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function unloadConn(array $message): array
    {
        return $this->requireSuccess('unload-conn', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadCert(array $message): array
    {
        return $this->requireSuccess('load-cert', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadKey(array $message): array
    {
        return $this->requireSuccess('load-key', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function unloadKey(array $message): array
    {
        return $this->requireSuccess('unload-key', $message);
    }

    /** @return array<string, mixed> */
    public function getKeys(): array
    {
        return $this->request('get-keys');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadToken(array $message): array
    {
        return $this->requireSuccess('load-token', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadShared(array $message): array
    {
        return $this->requireSuccess('load-shared', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function unloadShared(array $message): array
    {
        return $this->requireSuccess('unload-shared', $message);
    }

    /** @return array<string, mixed> */
    public function getShared(): array
    {
        return $this->request('get-shared');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function flushCerts(array $message = []): array
    {
        return $this->requireSuccess('flush-certs', $message);
    }

    /** @return array<string, mixed> */
    public function clearCreds(): array
    {
        return $this->requireSuccess('clear-creds');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadAuthority(array $message): array
    {
        return $this->requireSuccess('load-authority', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function unloadAuthority(array $message): array
    {
        return $this->requireSuccess('unload-authority', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function loadPool(array $message): array
    {
        return $this->requireSuccess('load-pool', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function unloadPool(array $message): array
    {
        return $this->requireSuccess('unload-pool', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function getPools(array $message = []): array
    {
        return $this->request('get-pools', $message);
    }

    /** @return array<string, mixed> */
    public function getAlgorithms(): array
    {
        return $this->request('get-algorithms');
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function getCounters(array $message = []): array
    {
        return $this->requireSuccess('get-counters', $message);
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>
     */
    public function resetCounters(array $message = []): array
    {
        return $this->requireSuccess('reset-counters', $message);
    }
}
