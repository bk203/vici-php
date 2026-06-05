<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Snapshot of transport state captured when a {@see ConnectionException} is raised.
 */
final class ConnectionFailureContext
{
    /**
     * @param array<string, mixed>|null $streamMeta
     */
    public function __construct(
        public readonly ?string $operation = null,
        public readonly ?string $endpoint = null,
        public readonly ?array $streamMeta = null,
        public readonly ?int $expectedBytes = null,
        public readonly ?int $receivedBytes = null,
        public readonly ?int $errno = null,
        public readonly ?string $phpError = null,
    ) {
    }

    public function format(): string
    {
        $parts = [];

        if ($this->operation !== null) {
            $parts[] = 'operation=' . $this->operation;
        }
        if ($this->endpoint !== null) {
            $parts[] = 'endpoint=' . $this->endpoint;
        }
        if ($this->expectedBytes !== null) {
            $parts[] = 'expected_bytes=' . $this->expectedBytes;
        }
        if ($this->receivedBytes !== null) {
            $parts[] = 'received_bytes=' . $this->receivedBytes;
        }
        if ($this->streamMeta !== null) {
            if (\array_key_exists('eof', $this->streamMeta)) {
                $parts[] = 'stream_eof=' . ($this->streamMeta['eof'] ? '1' : '0');
            }
            if (\array_key_exists('timed_out', $this->streamMeta)) {
                $parts[] = 'stream_timed_out=' . ($this->streamMeta['timed_out'] ? '1' : '0');
            }
            if (\array_key_exists('unread_bytes', $this->streamMeta)) {
                $parts[] = 'stream_unread_bytes=' . $this->streamMeta['unread_bytes'];
            }
            if (\array_key_exists('blocked', $this->streamMeta)) {
                $parts[] = 'stream_blocked=' . ($this->streamMeta['blocked'] ? '1' : '0');
            }
            if (\array_key_exists('stream_type', $this->streamMeta)) {
                $parts[] = 'stream_type=' . $this->streamMeta['stream_type'];
            }
        }
        if ($this->errno !== null) {
            $parts[] = 'errno=' . $this->errno;
        }
        if ($this->phpError !== null) {
            $parts[] = 'php_error=' . $this->phpError;
        }

        return implode(' ', $parts);
    }
}
