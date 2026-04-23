<?php

declare(strict_types=1);

namespace Bk203\Vici;

use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Message\MessageDecoder;
use Bk203\Vici\Protocol\PacketCodec;
use Bk203\Vici\Protocol\PacketType;

/**
 * Convenience API around {@see Session} for long-running event subscriptions.
 *
 * The listener reuses the existing session connection: it (de-)registers
 * events on the daemon via the session's registration counter and dispatches
 * incoming EVENT packets to user-provided callbacks.
 *
 * Because a single VICI connection allows only one in-flight command at a
 * time, callers should generally either give the listener a dedicated
 * session or keep commands and listen loops on separate threads/processes.
 */
final class EventListener
{
    /** @var array<string, list<callable(string $name, array<string, mixed> $message): void>> */
    private array $handlers = [];

    /** @var list<string> */
    private array $registered = [];

    private readonly PacketCodec $packetCodec;
    private readonly MessageDecoder $messageDecoder;

    public function __construct(
        private readonly Session $session,
    ) {
        $this->packetCodec = new PacketCodec();
        $this->messageDecoder = new MessageDecoder();
    }

    /**
     * Register a handler for the given event name. Use the constants on
     * {@see Event} where possible.
     *
     * @param callable(string $name, array<string, mixed> $message): void $handler
     */
    public function on(string $event, callable $handler): self
    {
        $this->handlers[$event][] = $handler;
        $this->session->onEvent($event, $handler);

        return $this;
    }

    /**
     * Subscribe to each named event on the daemon. Duplicate calls are safe
     * (reference-counted inside the Session).
     *
     * @param list<string> $events
     */
    public function register(array $events): self
    {
        foreach ($events as $event) {
            $this->session->registerEvent($event);
            $this->registered[] = $event;
        }

        return $this;
    }

    /**
     * Unregister the events previously registered through this listener. If
     * $events is omitted, all tracked registrations are released.
     *
     * @param list<string>|null $events
     */
    public function unregister(?array $events = null): self
    {
        $targets = $events ?? $this->registered;
        foreach ($targets as $event) {
            $this->session->unregisterEvent($event);
            $idx = array_search($event, $this->registered, true);
            if ($idx !== false) {
                array_splice($this->registered, $idx, 1);
            }
        }

        return $this;
    }

    /**
     * Block and dispatch incoming events.
     *
     * Events are read and dispatched one at a time; $stopWhen is evaluated
     * after each dispatch so the loop can be terminated before any buffered
     * follow-up packets are consumed.
     *
     * @param float|null $timeout Max seconds to run; null = forever.
     * @param callable():bool|null $stopWhen Optional predicate evaluated
     *        after each dispatched event; returning true ends the loop.
     */
    public function listen(?float $timeout = null, ?callable $stopWhen = null): void
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0.0) {
                return;
            }

            if ($this->readAndDispatchOne($remaining) === null) {
                return;
            }

            if ($stopWhen !== null && $stopWhen()) {
                return;
            }
        }
    }

    /**
     * Wait for a single event and return its decoded payload, or null if the
     * timeout elapsed first. Useful for test harnesses and simple polling.
     *
     * @return array{name: string, message: array<string, mixed>}|null
     */
    public function next(?float $timeout = null): ?array
    {
        return $this->readAndDispatchOne($timeout);
    }

    /**
     * Read and dispatch exactly one event packet, returning its decoded form,
     * or null on timeout.
     *
     * @return array{name: string, message: array<string, mixed>}|null
     */
    private function readAndDispatchOne(?float $timeout): ?array
    {
        $transport = $this->session->transport();
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.0) {
                    return null;
                }
                $wait = $remaining;
            } else {
                $wait = 1.0;
            }

            $wait = min($wait, 1.0);
            if (!$transport->hasData($wait)) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return null;
                }
                continue;
            }

            $packet = $this->packetCodec->decode($transport->receive());
            if ($packet->type !== PacketType::EVENT) {
                throw new ProtocolException(\sprintf(
                    'Unexpected packet %s while waiting for event.',
                    $packet->type->name,
                ));
            }
            /** @var string $name */
            $name = $packet->name;
            $message = $packet->payload === ''
                ? []
                : $this->messageDecoder->decode($packet->payload);

            foreach ($this->handlers[$name] ?? [] as $handler) {
                $handler($name, $message);
            }

            return ['name' => $name, 'message' => $message];
        }
    }
}
