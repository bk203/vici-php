<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;
use Bk203\Vici\Exception\ConnectionFailureContext;

/**
 * Connects to a charon VICI TCP socket (used when charon is configured with a
 * TCP listener, typically for cross-host / container scenarios).
 */
final class TcpSocketTransport extends SocketTransport
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly float $connectTimeout = 5.0,
        ?float $readTimeout = null,
    ) {
        parent::__construct($readTimeout);
        $this->connect();
    }

    protected function getEndpointDescription(): string
    {
        return \sprintf('tcp://%s:%d', $this->host, $this->port);
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            \sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->connectTimeout,
            \STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new ConnectionException(
                \sprintf(
                    'Failed to connect to VICI TCP %s:%d: [%d] %s',
                    $this->host,
                    $this->port,
                    $errno,
                    $errstr,
                ),
                new ConnectionFailureContext(
                    operation: 'connect',
                    endpoint: $this->getEndpointDescription(),
                    errno: $errno,
                    phpError: $errstr !== '' ? $errstr : null,
                ),
            );
        }

        stream_set_blocking($stream, true);
        if ($this->readTimeout !== null) {
            $sec = (int) floor($this->readTimeout);
            $usec = (int) round(($this->readTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }

        $this->stream = $stream;
    }
}
