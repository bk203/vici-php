<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Unit\Protocol;

use Bk203\Vici\Exception\ProtocolException;
use Bk203\Vici\Protocol\Packet;
use Bk203\Vici\Protocol\PacketCodec;
use Bk203\Vici\Protocol\PacketType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PacketCodec::class)]
#[CoversClass(Packet::class)]
#[CoversClass(PacketType::class)]
final class PacketCodecTest extends TestCase
{
    public function testCmdRequestRoundTrip(): void
    {
        $codec = new PacketCodec();
        $packet = Packet::cmdRequest('version');

        $encoded = $codec->encode($packet);
        self::assertSame(
            \chr(0) . \chr(7) . 'version',
            $encoded,
        );

        $decoded = $codec->decode($encoded);
        self::assertSame(PacketType::CMD_REQUEST, $decoded->type);
        self::assertSame('version', $decoded->name);
        self::assertSame('', $decoded->payload);
    }

    public function testCmdRequestWithPayload(): void
    {
        $codec = new PacketCodec();
        $payload = "\x03\x04name\x00\x05value";
        $packet = Packet::cmdRequest('load-conn', $payload);

        $encoded = $codec->encode($packet);
        self::assertSame(
            \chr(0) . \chr(9) . 'load-conn' . $payload,
            $encoded,
        );

        $decoded = $codec->decode($encoded);
        self::assertSame('load-conn', $decoded->name);
        self::assertSame($payload, $decoded->payload);
    }

    public function testCmdResponseRoundTrip(): void
    {
        $codec = new PacketCodec();
        $payload = "\x03\x01k\x00\x01v";
        $encoded = $codec->encode(Packet::cmdResponse($payload));
        self::assertSame(\chr(1) . $payload, $encoded);

        $decoded = $codec->decode($encoded);
        self::assertSame(PacketType::CMD_RESPONSE, $decoded->type);
        self::assertNull($decoded->name);
        self::assertSame($payload, $decoded->payload);
    }

    public function testUnnamedPacketsDecode(): void
    {
        $codec = new PacketCodec();
        self::assertSame(PacketType::CMD_UNKNOWN, $codec->decode(\chr(2))->type);
        self::assertSame(PacketType::EVENT_CONFIRM, $codec->decode(\chr(5))->type);
        self::assertSame(PacketType::EVENT_UNKNOWN, $codec->decode(\chr(6))->type);
    }

    public function testEventPacketRoundTrip(): void
    {
        $codec = new PacketCodec();
        $payload = "\x03\x02up\x00\x03yes";
        $encoded = $codec->encode(Packet::event('ike-updown', $payload));

        $decoded = $codec->decode($encoded);
        self::assertSame(PacketType::EVENT, $decoded->type);
        self::assertSame('ike-updown', $decoded->name);
        self::assertSame($payload, $decoded->payload);
    }

    public function testEmptyPacketBytesThrow(): void
    {
        $this->expectException(ProtocolException::class);
        (new PacketCodec())->decode('');
    }

    public function testUnknownPacketTypeThrows(): void
    {
        $this->expectException(ProtocolException::class);
        (new PacketCodec())->decode(\chr(99));
    }

    public function testTruncatedNameThrows(): void
    {
        $this->expectException(ProtocolException::class);
        (new PacketCodec())->decode(\chr(0) . \chr(10) . 'short');
    }

    public function testUnnamedPacketWithTrailingBytesThrows(): void
    {
        $this->expectException(ProtocolException::class);
        (new PacketCodec())->decode(\chr(2) . 'garbage');
    }

    public function testNamedPacketRequiresNonEmptyName(): void
    {
        $this->expectException(ProtocolException::class);
        (new PacketCodec())->decode(\chr(0) . \chr(0));
    }

    public function testConstructorRejectsNameOnUnnamedType(): void
    {
        $this->expectException(ProtocolException::class);
        new Packet(PacketType::CMD_RESPONSE, 'oops');
    }

    public function testConstructorRejectsPayloadOnMessagelessType(): void
    {
        $this->expectException(ProtocolException::class);
        new Packet(PacketType::EVENT_CONFIRM, null, 'oops');
    }

    public function testConstructorRejectsMissingNameOnNamedType(): void
    {
        $this->expectException(ProtocolException::class);
        new Packet(PacketType::CMD_REQUEST, '');
    }
}
