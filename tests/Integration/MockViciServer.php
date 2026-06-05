<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Message\MessageDecoder;
use Bk203\Vici\Message\MessageEncoder;
use Bk203\Vici\Protocol\Packet;
use Bk203\Vici\Protocol\PacketCodec;
use Bk203\Vici\Protocol\PacketType;
use Bk203\Vici\Transport\StreamTransport;
use PHPUnit\Framework\Assert;

/**
 * In-process VICI "server" backed by one half of a stream socket pair.
 *
 * The paired {@see getClientTransport()} can be handed to {@see \Bk203\Vici\Session}
 * while tests drive the server side synchronously: reading the client's
 * request frames, asserting their content, and writing responses/events.
 *
 * Because the underlying socket pair is buffered (64 KiB+ on all supported
 * platforms) single-test scenarios can push a batch of events plus the
 * terminating CMD_RESPONSE before the client reads any of them.
 */
final class MockViciServer
{
    /** @var resource */
    private $serverStream;

    private StreamTransport $clientTransport;
    private readonly PacketCodec $codec;
    private readonly MessageEncoder $encoder;
    private readonly MessageDecoder $decoder;

    public function __construct()
    {
        $pair = stream_socket_pair(
            \STREAM_PF_UNIX,
            \STREAM_SOCK_STREAM,
            \STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            throw new \RuntimeException('Failed to create socket pair for MockViciServer.');
        }
        [$clientSide, $serverSide] = $pair;
        $this->serverStream = $serverSide;

        $this->clientTransport = new StreamTransport($clientSide, readTimeout: 2.0);
        $this->codec = new PacketCodec();
        $this->encoder = new MessageEncoder();
        $this->decoder = new MessageDecoder();
    }

    public function getClientTransport(): StreamTransport
    {
        return $this->clientTransport;
    }

    public function close(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        $this->clientTransport->close();
    }

    /**
     * Close the current socket pair and replace it with a fresh one, as if
     * charon had restarted. Callers using {@see StreamTransport} must adopt
     * {@see getClientTransport()} again; {@see ReconnectingTransport} clients
     * recover via automatic reconnect on the next I/O.
     */
    public function simulateRestart(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        $this->clientTransport->close();

        $pair = stream_socket_pair(
            \STREAM_PF_UNIX,
            \STREAM_SOCK_STREAM,
            \STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            throw new \RuntimeException('Failed to create socket pair for MockViciServer restart.');
        }
        [$clientSide, $serverSide] = $pair;
        $this->serverStream = $serverSide;
        $this->clientTransport = new StreamTransport($clientSide, readTimeout: 2.0);
    }

    // ------------------------------------------------------------------
    // Inbound (read what the client sent).
    // ------------------------------------------------------------------

    public function readPacket(float $timeout = 1.0): Packet
    {
        $deadline = microtime(true) + $timeout;
        $read = [$this->serverStream];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, (int) floor($timeout), 0);
        if ($ready === false || $ready === 0) {
            Assert::fail('Timed out waiting for VICI packet from client.');
        }

        $header = $this->readExactly(4, $deadline);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        $length = $unpacked[1];
        $body = $length === 0 ? '' : $this->readExactly($length, $deadline);

        return $this->codec->decode($body);
    }

    /**
     * Assert the next packet is a CMD_REQUEST named $command and return its
     * decoded argument message.
     *
     * @return array<string, mixed>
     */
    public function expectCommand(string $command, float $timeout = 1.0): array
    {
        $packet = $this->readPacket($timeout);
        Assert::assertSame(PacketType::CMD_REQUEST, $packet->type, \sprintf(
            'Expected CMD_REQUEST "%s"; got %s.',
            $command,
            $packet->type->name,
        ));
        Assert::assertSame($command, $packet->name);

        return $packet->payload === '' ? [] : $this->decoder->decode($packet->payload);
    }

    public function expectEventRegister(string $event, float $timeout = 1.0): void
    {
        $packet = $this->readPacket($timeout);
        Assert::assertSame(PacketType::EVENT_REGISTER, $packet->type);
        Assert::assertSame($event, $packet->name);
    }

    public function expectEventUnregister(string $event, float $timeout = 1.0): void
    {
        $packet = $this->readPacket($timeout);
        Assert::assertSame(PacketType::EVENT_UNREGISTER, $packet->type);
        Assert::assertSame($event, $packet->name);
    }

    // ------------------------------------------------------------------
    // Outbound (push data to the client).
    // ------------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $message
     */
    public function sendCmdResponse(array $message = []): void
    {
        $this->writePacket(Packet::cmdResponse($this->encoder->encode($message)));
    }

    public function sendCmdUnknown(): void
    {
        $this->writePacket(Packet::cmdUnknown());
    }

    public function sendEventConfirm(): void
    {
        $this->writePacket(Packet::eventConfirm());
    }

    public function sendEventUnknown(): void
    {
        $this->writePacket(Packet::eventUnknown());
    }

    /**
     * @param array<array-key, mixed> $message
     */
    public function sendEvent(string $event, array $message = []): void
    {
        $this->writePacket(Packet::event($event, $this->encoder->encode($message)));
    }

    private function writePacket(Packet $packet): void
    {
        $bytes = $this->codec->encode($packet);
        $frame = pack('N', \strlen($bytes)) . $bytes;
        $written = 0;
        $total = \strlen($frame);
        while ($written < $total) {
            $chunk = fwrite($this->serverStream, substr($frame, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \RuntimeException('MockViciServer failed to write packet.');
            }
            $written += $chunk;
        }
        fflush($this->serverStream);
    }

    private function readExactly(int $length, float $deadline): string
    {
        $buf = '';
        while (\strlen($buf) < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                Assert::fail(\sprintf(
                    'Timed out reading %d bytes from client (got %d).',
                    $length,
                    \strlen($buf),
                ));
            }
            $read = [$this->serverStream];
            $write = null;
            $except = null;
            $sec = (int) floor($remaining);
            $usec = (int) round(($remaining - $sec) * 1_000_000);
            $ready = stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                Assert::fail('Timed out reading from client.');
            }
            $need = $length - \strlen($buf);
            \assert($need > 0);
            $chunk = fread($this->serverStream, $need);
            if ($chunk === false || $chunk === '') {
                if (feof($this->serverStream)) {
                    throw new \RuntimeException('Client closed connection unexpectedly.');
                }
                continue;
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
