<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Transport;

use Bk203\Vici\Transport\ReconnectingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReconnectingTransport::class)]
final class ReconnectingTransportTest extends TestCase
{
    /** @var resource|null */
    private $listenSocket = null;

    /** @var resource|null */
    private $serverStream = null;

    private string $socketPath;

    protected function setUp(): void
    {
        $this->socketPath = sys_get_temp_dir() . '/vici-reconnect-test-' . uniqid('', true) . '.sock';
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

    public function testReceiveRetriesAfterServerRestart(): void
    {
        $transport = new ReconnectingTransport(
            path: $this->socketPath,
            connectTimeout: 2.0,
            readTimeout: 2.0,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 50,
        );
        $this->acceptClient();

        $serverStream = $this->requireServerStream();
        fwrite($serverStream, pack('N', 5) . 'first');
        fflush($serverStream);
        self::assertSame('first', $transport->receive());

        $this->simulateServerRestart();
        $pid = $this->forkAcceptAndWrite(pack('N', 6) . 'second');
        self::assertSame('second', $transport->receive());
        $this->waitFork($pid);

        $transport->close();
    }

    public function testSendRetriesAfterServerRestart(): void
    {
        $transport = new ReconnectingTransport(
            path: $this->socketPath,
            connectTimeout: 2.0,
            readTimeout: 2.0,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 50,
        );
        $this->acceptClient();

        $transport->send('payload');
        $serverStream = $this->requireServerStream();
        $header = fread($serverStream, 4);
        self::assertIsString($header);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        self::assertSame(7, $unpacked[1]);
        self::assertSame('payload', fread($serverStream, 7));

        $this->simulateServerRestart();
        $pid = $this->forkAcceptAndReadExpectedFrame(5, 'again');

        $transport->send('again');
        $this->waitFork($pid);

        $transport->close();
    }

    private function simulateServerRestart(): void
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
        $this->startListener();
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

    private function forkAcceptAndWrite(string $bytes): int
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

        $listenSocket = $this->requireListenSocket();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            $client = stream_socket_accept($listenSocket, 5.0);
            if ($client === false) {
                exit(1);
            }
            fwrite($client, $bytes);
            fflush($client);
            exit(0);
        }

        return $pid;
    }

    private function forkAcceptAndReadExpectedFrame(int $length, string $payload): int
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

        $listenSocket = $this->requireListenSocket();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            $client = stream_socket_accept($listenSocket, 5.0);
            if ($client === false) {
                exit(1);
            }
            $header = fread($client, 4);
            $body = $length > 0 ? fread($client, $length) : '';
            if (!\is_string($header) || !\is_string($body)) {
                exit(1);
            }
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $header);
            if ($unpacked[1] !== $length || $body !== $payload) {
                exit(1);
            }
            exit(0);
        }

        return $pid;
    }

    private function waitFork(int $pid): void
    {
        $status = 0;
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
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
