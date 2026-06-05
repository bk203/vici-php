<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Message\MessageDecoder;
use Bk203\Vici\Message\MessageEncoder;
use Bk203\Vici\Protocol\Packet;
use Bk203\Vici\Protocol\PacketCodec;
use Bk203\Vici\Protocol\PacketType;
use PHPUnit\Framework\Assert;

/**
 * In-process VICI server bound to a filesystem Unix socket path.
 *
 * Used to exercise {@see \Bk203\Vici\Transport\ReconnectingTransport} and
 * {@see \Bk203\Vici\Transport\UnixSocketTransport::reconnect()} against a
 * socket file that can be recreated like charon does on restart.
 */
final class FileSocketViciServer
{
    /** @var resource|null */
    private $listenSocket = null;

    /** @var resource|null */
    private $serverStream = null;

    private readonly PacketCodec $codec;
    private readonly MessageEncoder $encoder;
    private readonly MessageDecoder $decoder;

    public readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? sys_get_temp_dir() . '/vici-file-test-' . uniqid('', true) . '.sock';
        $this->codec = new PacketCodec();
        $this->encoder = new MessageEncoder();
        $this->decoder = new MessageDecoder();
        $this->startListening();
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function acceptClient(float $timeout = 2.0): void
    {
        if (!\is_resource($this->listenSocket)) {
            Assert::fail('FileSocketViciServer is not listening.');
        }

        $listenSocket = $this->listenSocket;
        $read = [$listenSocket];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, (int) floor($timeout), 0);
        if ($ready === false || $ready === 0) {
            Assert::fail('Timed out waiting for VICI client connection.');
        }

        $client = stream_socket_accept($listenSocket, $timeout);
        if ($client === false) {
            Assert::fail('Failed to accept VICI client connection.');
        }

        $this->serverStream = $client;
    }

    public function simulateRestart(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        $this->serverStream = null;

        if (\is_resource($this->listenSocket)) {
            @fclose($this->listenSocket);
        }
        $this->listenSocket = null;

        if (file_exists($this->path)) {
            @unlink($this->path);
        }

        $this->startListening();
    }

    public function close(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        if (\is_resource($this->listenSocket)) {
            @fclose($this->listenSocket);
        }
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    /**
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

    /**
     * @param array<array-key, mixed> $message
     */
    public function sendCmdResponse(array $message = []): void
    {
        $this->writePacket(Packet::cmdResponse($this->encoder->encode($message)));
    }

    private function readPacket(float $timeout = 1.0): Packet
    {
        if (!\is_resource($this->serverStream)) {
            Assert::fail('FileSocketViciServer has no accepted client.');
        }

        $serverStream = $this->serverStream;
        $deadline = microtime(true) + $timeout;
        $read = [$serverStream];
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

    private function writePacket(Packet $packet): void
    {
        if (!\is_resource($this->serverStream)) {
            throw new \RuntimeException('FileSocketViciServer has no accepted client.');
        }

        $serverStream = $this->serverStream;
        $bytes = $this->codec->encode($packet);
        $frame = pack('N', \strlen($bytes)) . $bytes;
        $written = 0;
        $total = \strlen($frame);
        while ($written < $total) {
            $chunk = fwrite($serverStream, substr($frame, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \RuntimeException('FileSocketViciServer failed to write packet.');
            }
            $written += $chunk;
        }
        fflush($serverStream);
    }

    private function readExactly(int $length, float $deadline): string
    {
        if (!\is_resource($this->serverStream)) {
            Assert::fail('FileSocketViciServer has no accepted client.');
        }

        $serverStream = $this->serverStream;
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
            $read = [$serverStream];
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
            $chunk = fread($serverStream, $need);
            if ($chunk === false || $chunk === '') {
                if (feof($serverStream)) {
                    throw new \RuntimeException('Client closed connection unexpectedly.');
                }
                continue;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private function startListening(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }

        $listenSocket = @stream_socket_server('unix://' . $this->path);
        if ($listenSocket === false) {
            throw new \RuntimeException('Failed to create FileSocketViciServer listener.');
        }

        $this->listenSocket = $listenSocket;
    }
}
