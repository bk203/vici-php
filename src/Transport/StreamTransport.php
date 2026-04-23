<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;

/**
 * Generic transport wrapping an arbitrary stream resource.
 *
 * Primarily intended for dependency injection, testing (via
 * {@see \stream_socket_pair()}) and advanced use cases where the caller has
 * already established a stream (e.g. behind a TLS tunnel).
 */
final class StreamTransport extends SocketTransport
{
    /**
     * @param resource $stream Already-connected stream resource.
     */
    public function __construct($stream, ?float $readTimeout = null)
    {
        if (!\is_resource($stream)) {
            throw new ConnectionException('StreamTransport requires a valid stream resource.');
        }
        parent::__construct($readTimeout);
        stream_set_blocking($stream, true);
        if ($readTimeout !== null) {
            $sec = (int) floor($readTimeout);
            $usec = (int) round(($readTimeout - $sec) * 1_000_000);
            stream_set_timeout($stream, $sec, $usec);
        }
        $this->stream = $stream;
    }
}
