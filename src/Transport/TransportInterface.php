<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

/**
 * Low-level transport abstraction for the VICI protocol.
 *
 * Implementations are responsible for the 32-bit network-order length framing
 * specified by the VICI transport layer (max segment 512 KiB). Higher layers
 * exchange unframed packet bodies only.
 *
 * All methods throw {@see \Bk203\Vici\Exception\ConnectionException} on
 * connection-level errors, {@see \Bk203\Vici\Exception\ProtocolException} on
 * framing violations, and {@see \Bk203\Vici\Exception\TimeoutException} when
 * a non-null timeout elapses before data is available.
 */
interface TransportInterface
{
    /** Maximum packet body size per VICI protocol README. */
    public const int MAX_SEGMENT_LENGTH = 524288;

    /**
     * Send an unframed packet body. The transport adds the 32-bit length prefix.
     */
    public function send(string $bytes): void;

    /**
     * Receive the next unframed packet body.
     *
     * @param float|null $timeout Seconds to wait for data (null = block).
     */
    public function receive(?float $timeout = null): string;

    /**
     * Returns true if at least one byte is readable within the given timeout.
     *
     * @param float $timeout Seconds to wait (0 = non-blocking poll).
     */
    public function hasData(float $timeout = 0.0): bool;

    public function isConnected(): bool;

    public function close(): void;

    /**
     * Expose the underlying stream resource (for `stream_select` multiplexing
     * by higher-level components such as EventListener). May return null if
     * the transport is closed.
     *
     * @return resource|null
     */
    public function getStream();
}
