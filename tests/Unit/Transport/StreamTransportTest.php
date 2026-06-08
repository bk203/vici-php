<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Transport;

use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Exception\TimeoutException;
use Bk203\Vici\Transport\StreamTransport;
use Bk203\Vici\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamTransport::class)]
#[CoversClass(\Bk203\Vici\Transport\SocketTransport::class)]
final class StreamTransportTest extends TestCase
{
    /**
     * @return array{0: StreamTransport, 1: resource}
     */
    private function makePair(?float $readTimeout = null): array
    {
        $pair = stream_socket_pair(
            \STREAM_PF_UNIX,
            \STREAM_SOCK_STREAM,
            \STREAM_IPPROTO_IP,
        );
        self::assertIsArray($pair);
        [$clientStream, $peerStream] = $pair;

        $transport = new StreamTransport($clientStream, $readTimeout);

        return [$transport, $peerStream];
    }

    public function testFramesOutgoingPayload(): void
    {
        [$transport, $peer] = $this->makePair();

        $transport->send('hello');

        $header = fread($peer, 4);
        self::assertIsString($header);
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        self::assertSame(5, $unpacked[1]);
        self::assertSame('hello', fread($peer, 5));

        $transport->close();
        fclose($peer);
    }

    public function testReadsFramedPayloadInPieces(): void
    {
        [$transport, $peer] = $this->makePair();

        // write header + payload in two separate writes to exercise the
        // partial-read loop
        fwrite($peer, pack('N', 11));
        fwrite($peer, 'hello ');
        fflush($peer);
        usleep(10_000);
        fwrite($peer, 'world');
        fflush($peer);

        self::assertSame('hello world', $transport->receive());

        $transport->close();
        fclose($peer);
    }

    public function testRejectsOversizedIncomingSegment(): void
    {
        [$transport, $peer] = $this->makePair();

        fwrite($peer, pack('N', TransportInterface::MAX_SEGMENT_LENGTH + 1));
        fflush($peer);

        $this->expectException(ProtocolException::class);
        try {
            $transport->receive();
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    public function testRejectsOversizedOutgoingSegment(): void
    {
        [$transport, $peer] = $this->makePair();

        $this->expectException(ProtocolException::class);
        try {
            $transport->send(str_repeat('x', TransportInterface::MAX_SEGMENT_LENGTH + 1));
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    public function testHasDataReturnsFalseWhenIdle(): void
    {
        [$transport, $peer] = $this->makePair();
        self::assertFalse($transport->hasData(0.0));
        $transport->close();
        fclose($peer);
    }

    public function testReceiveHonorsTimeout(): void
    {
        [$transport, $peer] = $this->makePair();
        $start = microtime(true);

        try {
            $transport->receive(0.1);
            self::fail('Expected TimeoutException.');
        } catch (TimeoutException) {
            $elapsed = microtime(true) - $start;
            self::assertLessThan(0.5, $elapsed);
        } finally {
            $transport->close();
            fclose($peer);
        }
    }

    public function testReceiveDetectsRemoteClose(): void
    {
        [$transport, $peer] = $this->makePair();
        fclose($peer);

        $this->expectException(\Bk203\Vici\Exception\ConnectionException::class);
        try {
            $transport->receive(0.5);
        } finally {
            self::assertFalse($transport->isConnected());
            $transport->close();
        }
    }

    public function testConnectionFailureInvalidatesStream(): void
    {
        [$transport, $peer] = $this->makePair();
        self::assertTrue($transport->isConnected());

        fclose($peer);

        try {
            $transport->receive(0.5);
            self::fail('Expected ConnectionException.');
        } catch (\Bk203\Vici\Exception\ConnectionException $e) {
            self::assertFalse($transport->isConnected());
            self::assertNull($transport->getStream());
            self::assertNotNull($e->context);
            self::assertSame('read', $e->context->operation);
            self::assertTrue($e->context->streamMeta['eof'] ?? false);
        }
    }

    public function testIsConnectedReflectsState(): void
    {
        [$transport, $peer] = $this->makePair();
        self::assertTrue($transport->isConnected());
        $transport->close();
        self::assertFalse($transport->isConnected());
        fclose($peer);
    }
}
