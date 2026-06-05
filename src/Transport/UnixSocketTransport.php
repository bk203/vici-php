<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;

/**
 * Connects to a charon VICI Unix domain socket.
 */
final class UnixSocketTransport extends SocketTransport implements ReconnectableTransportInterface
{
    public const string DEFAULT_PATH = '/var/run/charon.vici';

    /** @var (callable(): void)|null */
    private $onReconnect = null;

    public function __construct(
        public readonly string $path = self::DEFAULT_PATH,
        public readonly float $connectTimeout = 5.0,
        ?float $readTimeout = null,
    ) {
        parent::__construct($readTimeout);
        $this->connect();
    }

    public function reconnect(): void
    {
        $this->close();
        $this->connect();

        if ($this->onReconnect !== null) {
            ($this->onReconnect)();
        }
    }

    public function setOnReconnect(?callable $callback): void
    {
        $this->onReconnect = $callback;
    }

    protected function connect(): void
    {
        $deadline = microtime(true) + $this->connectTimeout;
        while (!file_exists($this->path) && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $remaining = max(0.0, $deadline - microtime(true));
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'unix://' . $this->path,
            $errno,
            $errstr,
            $remaining,
            \STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new ConnectionException(\sprintf(
                'Failed to connect to VICI socket %s: [%d] %s',
                $this->path,
                $errno,
                $errstr,
            ));
        }

        $this->applyStreamOptions($stream);
        $this->stream = $stream;
    }

    /**
     * @param resource $stream
     */
    private function applyStreamOptions($stream): void
    {
        stream_set_blocking($stream, true);
        if ($this->readTimeout !== null) {
            $sec = (int) floor($this->readTimeout);
            $usec = (int) round(($this->readTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }
    }
}
