<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

use Bk203\Vici\Exception\ConnectionException;
use Bk203\Vici\Exception\ConnectionFailureContext;
use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Exception\TimeoutException;

/**
 * Base class for stream-socket-based transports. Handles 32-bit length
 * framing, partial reads/writes, and timeout-aware I/O.
 */
abstract class SocketTransport implements TransportInterface
{
    /** @var resource|null */
    protected $stream = null;

    public function __construct(
        protected readonly ?float $readTimeout = null,
    ) {
    }

    final public function send(string $bytes): void
    {
        $length = \strlen($bytes);
        if ($length > self::MAX_SEGMENT_LENGTH) {
            throw new ProtocolException(\sprintf(
                'VICI transport segment exceeds %d bytes (got %d).',
                self::MAX_SEGMENT_LENGTH,
                $length,
            ));
        }

        $this->writeAll(pack('N', $length) . $bytes);
    }

    final public function receive(?float $timeout = null): string
    {
        $effective = $timeout ?? $this->readTimeout;

        $header = $this->readAll(4, $effective);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        $length = $unpacked[1];

        if ($length > self::MAX_SEGMENT_LENGTH) {
            throw new ProtocolException(\sprintf(
                'Incoming VICI transport segment claims length %d (max %d).',
                $length,
                self::MAX_SEGMENT_LENGTH,
            ));
        }
        if ($length === 0) {
            return '';
        }

        return $this->readAll($length, $effective);
    }

    final public function hasData(float $timeout = 0.0): bool
    {
        $stream = $this->requireStream();
        $read = [$stream];
        $write = null;
        $except = null;

        $sec = (int) floor($timeout);
        $usec = (int) round(($timeout - $sec) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $sec, $usec);

        if ($ready === false) {
            $this->connectionFailed(
                'stream_select() failed on VICI transport.',
                $this->buildFailureContext(
                    operation: 'select',
                    stream: $stream,
                    phpError: $this->capturePhpError(),
                ),
            );
        }

        return $ready > 0;
    }

    final public function isConnected(): bool
    {
        return \is_resource($this->stream) && !feof($this->stream);
    }

    final public function close(): void
    {
        if (\is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    final public function getStream()
    {
        return \is_resource($this->stream) ? $this->stream : null;
    }

    protected function invalidate(): void
    {
        $this->close();
    }

    protected function getEndpointDescription(): string
    {
        return '';
    }

    /**
     * @return never
     */
    protected function connectionFailed(string $message, ?ConnectionFailureContext $context = null): void
    {
        $this->invalidate();
        throw new ConnectionException($message, $context);
    }

    /**
     * @param resource|null $stream
     */
    protected function buildFailureContext(
        string $operation,
        $stream = null,
        ?int $expectedBytes = null,
        ?int $receivedBytes = null,
        ?int $errno = null,
        ?string $phpError = null,
    ): ConnectionFailureContext {
        $endpoint = $this->getEndpointDescription();

        return new ConnectionFailureContext(
            operation: $operation,
            endpoint: $endpoint !== '' ? $endpoint : null,
            streamMeta: \is_resource($stream) ? $this->captureStreamMeta($stream) : null,
            expectedBytes: $expectedBytes,
            receivedBytes: $receivedBytes,
            errno: $errno,
            phpError: $phpError,
        );
    }

    protected function capturePhpError(): ?string
    {
        $error = error_get_last();
        if ($error === null) {
            return null;
        }

        return $error['message'];
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>
     */
    protected function captureStreamMeta($stream): array
    {
        /** @var array<string, mixed> $meta */
        $meta = stream_get_meta_data($stream);

        return $meta;
    }

    private function writeAll(string $data): void
    {
        $stream = $this->requireStream();
        $total = \strlen($data);
        $written = 0;

        while ($written < $total) {
            $chunk = @fwrite($stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out']) {
                    throw new TimeoutException('Timed out writing to VICI socket.');
                }
                if (feof($stream)) {
                    $this->connectionFailed(
                        'VICI socket closed during write.',
                        $this->buildFailureContext(
                            operation: 'write',
                            stream: $stream,
                            expectedBytes: $total - $written,
                            receivedBytes: $written,
                        ),
                    );
                }
                $this->connectionFailed(
                    'Failed to write to VICI socket.',
                    $this->buildFailureContext(
                        operation: 'write',
                        stream: $stream,
                        expectedBytes: $total - $written,
                        receivedBytes: $written,
                        phpError: $this->capturePhpError(),
                    ),
                );
            }
            $written += $chunk;
        }
    }

    private function readAll(int $length, ?float $timeout): string
    {
        $stream = $this->requireStream();
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $buffer = '';

        while (\strlen($buffer) < $length) {
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0.0) {
                    throw new TimeoutException('Timed out reading from VICI socket.');
                }
                $read = [$stream];
                $write = null;
                $except = null;
                $sec = (int) floor($remaining);
                $usec = (int) round(($remaining - $sec) * 1_000_000);
                $ready = @stream_select($read, $write, $except, $sec, $usec);
                if ($ready === false) {
                    $this->connectionFailed(
                        'stream_select() failed on VICI transport.',
                        $this->buildFailureContext(
                            operation: 'select',
                            stream: $stream,
                            expectedBytes: $length,
                            receivedBytes: \strlen($buffer),
                            phpError: $this->capturePhpError(),
                        ),
                    );
                }
                if ($ready === 0) {
                    throw new TimeoutException('Timed out reading from VICI socket.');
                }
            }

            $need = $length - \strlen($buffer);
            \assert($need > 0);
            $chunk = @fread($stream, $need);
            if ($chunk === false) {
                $this->connectionFailed(
                    'Failed to read from VICI socket.',
                    $this->buildFailureContext(
                        operation: 'read',
                        stream: $stream,
                        expectedBytes: $need,
                        receivedBytes: \strlen($buffer),
                        phpError: $this->capturePhpError(),
                    ),
                );
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    $this->connectionFailed(
                        'VICI socket closed during read.',
                        $this->buildFailureContext(
                            operation: 'read',
                            stream: $stream,
                            expectedBytes: $need,
                            receivedBytes: \strlen($buffer),
                        ),
                    );
                }
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out']) {
                    throw new TimeoutException('Timed out reading from VICI socket.');
                }
                usleep(1000);
                continue;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @return resource
     */
    private function requireStream()
    {
        if (!\is_resource($this->stream)) {
            $this->connectionFailed(
                'VICI transport is not connected.',
                $this->buildFailureContext(operation: 'require_stream'),
            );
        }
        return $this->stream;
    }
}
