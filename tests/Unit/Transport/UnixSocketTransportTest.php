<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Transport;

use Bk203\Vici\Exception\ConnectionException;
use Bk203\Vici\Transport\UnixSocketTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnixSocketTransport::class)]
final class UnixSocketTransportTest extends TestCase
{
    /** @var resource|null */
    private $listenSocket = null;

    /** @var resource|null */
    private $serverStream = null;

    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir() . '/vici-test-' . uniqid('', true) . '.sock';
        $this->startListener();
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        if (\is_resource($this->listenSocket)) {
            @fclose($this->listenSocket);
        }
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    public function testReconnectOpensNewConnectionAfterPeerCloses(): void
    {
        $transport = new UnixSocketTransport($this->socketPath, connectTimeout: 2.0, readTimeout: 2.0);
        $this->acceptClient();

        $serverStream = $this->requireServerStream();
        fwrite($serverStream, pack('N', 5) . 'hello');
        fflush($serverStream);
        self::assertSame('hello', $transport->receive());

        $this->closeListener();

        $this->startListener();

        $transport->reconnect();
        $this->acceptClient();
        self::assertTrue($transport->isConnected());

        $serverStream = $this->requireServerStream();
        fwrite($serverStream, pack('N', 5) . 'world');
        fflush($serverStream);
        self::assertSame('world', $transport->receive());

        $transport->close();
    }

    public function testReconnectThrowsWhenSocketFileMissing(): void
    {
        $transport = new UnixSocketTransport($this->socketPath, connectTimeout: 0.2, readTimeout: 2.0);
        $this->acceptClient();

        $this->closeListener();

        $this->expectException(ConnectionException::class);
        $transport->reconnect();
    }

    private function startListener(): void
    {
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $listenSocket = @stream_socket_server('unix://' . $this->socketPath);
        if ($listenSocket === false) {
            self::fail('Failed to create unix socket listener.');
        }

        $this->listenSocket = $listenSocket;
    }

    private function acceptClient(float $timeout = 2.0): void
    {
        $listenSocket = $this->requireListenSocket();
        $read = [$listenSocket];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, (int) floor($timeout), 0);
        if ($ready === false || $ready === 0) {
            self::fail('Timed out waiting for client connection.');
        }

        $client = stream_socket_accept($listenSocket, $timeout);
        if ($client === false) {
            self::fail('Failed to accept client connection.');
        }

        $this->serverStream = $client;
    }

    private function closeListener(): void
    {
        if (\is_resource($this->serverStream)) {
            @fclose($this->serverStream);
        }
        $this->serverStream = null;
        if (\is_resource($this->listenSocket)) {
            @fclose($this->listenSocket);
        }
        $this->listenSocket = null;
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    /**
     * @return resource
     */
    private function requireListenSocket()
    {
        if (!\is_resource($this->listenSocket)) {
            self::fail('Unix socket listener is not open.');
        }

        return $this->listenSocket;
    }

    /**
     * @return resource
     */
    private function requireServerStream()
    {
        if (!\is_resource($this->serverStream)) {
            self::fail('No accepted client connection.');
        }

        return $this->serverStream;
    }
}
