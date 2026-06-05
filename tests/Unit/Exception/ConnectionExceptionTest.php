<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Exception;

use Bk203\Vici\Exception\ConnectionException;
use Bk203\Vici\Exception\ConnectionFailureContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionException::class)]
#[CoversClass(ConnectionFailureContext::class)]
final class ConnectionExceptionTest extends TestCase
{
    public function testShortMessageUnchangedWithoutContext(): void
    {
        $exception = new ConnectionException('Failed to read from VICI socket.');

        self::assertSame('Failed to read from VICI socket.', $exception->getMessage());
        self::assertNull($exception->context);
        self::assertSame('Failed to read from VICI socket.', $exception->getDetailedMessage());
    }

    public function testDetailedMessageIncludesDiagnostics(): void
    {
        $exception = new ConnectionException(
            'Failed to read from VICI socket.',
            new ConnectionFailureContext(
                operation: 'read',
                endpoint: 'unix:///var/run/charon.vici (socket file exists)',
                streamMeta: ['eof' => false, 'timed_out' => false],
                expectedBytes: 4,
                receivedBytes: 0,
                phpError: 'fread(): Broken pipe',
            ),
        );

        self::assertSame('Failed to read from VICI socket.', $exception->getMessage());
        self::assertStringContainsString('operation=read', $exception->getDetailedMessage());
        self::assertStringContainsString('endpoint=unix:///var/run/charon.vici (socket file exists)', $exception->getDetailedMessage());
        self::assertStringContainsString('expected_bytes=4', $exception->getDetailedMessage());
        self::assertStringContainsString('received_bytes=0', $exception->getDetailedMessage());
        self::assertStringContainsString('php_error=fread(): Broken pipe', $exception->getDetailedMessage());
    }
}
