<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;

/**
 * Connects to a charon VICI Unix domain socket.
 */
final class UnixSocketTransport extends SocketTransport
{
    public const string DEFAULT_PATH = '/var/run/charon.vici';

    public function __construct(
        public readonly string $path = self::DEFAULT_PATH,
        public readonly float $connectTimeout = 5.0,
        ?float $readTimeout = null,
    ) {
        parent::__construct($readTimeout);
        $this->connect();
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            'unix://' . $this->path,
            $errno,
            $errstr,
            $this->connectTimeout,
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

        stream_set_blocking($stream, true);
        if ($this->readTimeout !== null) {
            $sec = (int) floor($this->readTimeout);
            $usec = (int) round(($this->readTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }

        $this->stream = $stream;
    }
}
