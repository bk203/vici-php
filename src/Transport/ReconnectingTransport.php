<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;

/**
 * Transport wrapper that reconnects to a Unix VICI socket after connection
 * failures. Intended for long-lived loops where charon may restart and
 * recreate the socket file.
 */
final class ReconnectingTransport implements TransportInterface
{
    private UnixSocketTransport $inner;

    public function __construct(
        public readonly string $path = UnixSocketTransport::DEFAULT_PATH,
        public readonly float $connectTimeout = 5.0,
        public readonly ?float $readTimeout = 30.0,
        public readonly int $maxReconnectAttempts = 3,
        public readonly int $reconnectDelayMs = 200,
    ) {
        $this->inner = new UnixSocketTransport(
            $this->path,
            $this->connectTimeout,
            $this->readTimeout,
        );
    }

    public function send(string $bytes): void
    {
        $this->withReconnect(function (UnixSocketTransport $inner) use ($bytes): void {
            $inner->send($bytes);
        });
    }

    public function receive(?float $timeout = null): string
    {
        return $this->withReconnect(static fn (UnixSocketTransport $inner): string => $inner->receive($timeout));
    }

    public function hasData(float $timeout = 0.0): bool
    {
        return $this->withReconnect(static fn (UnixSocketTransport $inner): bool => $inner->hasData($timeout));
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function getStream()
    {
        return $this->inner->getStream();
    }

    /**
     * @template T
     *
     * @param callable(UnixSocketTransport): T $operation
     *
     * @return T
     */
    private function withReconnect(callable $operation): mixed
    {
        try {
            return $operation($this->inner);
        } catch (ConnectionException $first) {
            $last = $first;

            for ($attempt = 0; $attempt < $this->maxReconnectAttempts; $attempt++) {
                usleep($this->reconnectDelayMs * 1000);

                try {
                    $this->inner->reconnect();
                } catch (ConnectionException $e) {
                    $last = $e;
                    continue;
                }

                try {
                    return $operation($this->inner);
                } catch (ConnectionException $e) {
                    $last = $e;
                }
            }

            throw $last;
        }
    }
}
