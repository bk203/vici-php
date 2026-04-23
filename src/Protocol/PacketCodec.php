<?php

declare(strict_types=1);

namespace Bk203\Vici\Protocol;

use Bk203\Vici\Exception\ProtocolException;

/**
 * Encodes and decodes VICI packets according to the protocol's packet layer.
 *
 * The transport layer (length-prefixed framing) is handled by the
 * {@see \Bk203\Vici\Transport\TransportInterface} implementations; this codec
 * works with unframed packet bodies only.
 */
final class PacketCodec
{
    /** Maximum VICI name tag length (8-bit length prefix). */
    public const int MAX_NAME_LENGTH = 255;

    public function encode(Packet $packet): string
    {
        $out = \chr($packet->type->value);

        if ($packet->type->hasName()) {
            /** @var string $name */
            $name = $packet->name;
            $len = \strlen($name);
            if ($len === 0 || $len > self::MAX_NAME_LENGTH) {
                throw new ProtocolException(\sprintf(
                    'VICI packet name length %d is out of range (1..255).',
                    $len,
                ));
            }
            $out .= \chr($len) . $name;
        }

        if ($packet->type->hasMessage()) {
            $out .= $packet->payload;
        }

        return $out;
    }

    public function decode(string $bytes): Packet
    {
        if ($bytes === '') {
            throw new ProtocolException('Cannot decode empty VICI packet.');
        }

        $typeByte = \ord($bytes[0]);
        $type = PacketType::tryFrom($typeByte);
        if ($type === null) {
            throw new ProtocolException(\sprintf(
                'Unknown VICI packet type 0x%02x.',
                $typeByte,
            ));
        }

        $pos = 1;
        $name = null;
        if ($type->hasName()) {
            if ($pos >= \strlen($bytes)) {
                throw new ProtocolException(\sprintf(
                    'Truncated %s packet: missing name length byte.',
                    $type->name,
                ));
            }
            $nameLen = \ord($bytes[$pos]);
            $pos++;
            if ($nameLen === 0) {
                throw new ProtocolException(\sprintf(
                    'Packet type %s requires a non-empty name.',
                    $type->name,
                ));
            }
            if ($pos + $nameLen > \strlen($bytes)) {
                throw new ProtocolException(\sprintf(
                    'Truncated %s packet: name length %d exceeds remaining bytes.',
                    $type->name,
                    $nameLen,
                ));
            }
            $name = substr($bytes, $pos, $nameLen);
            $pos += $nameLen;
        }

        $payload = '';
        if ($type->hasMessage()) {
            $payload = substr($bytes, $pos);
        } elseif ($pos !== \strlen($bytes)) {
            throw new ProtocolException(\sprintf(
                'Packet type %s must not carry trailing bytes (%d extra).',
                $type->name,
                \strlen($bytes) - $pos,
            ));
        }

        return new Packet($type, $name, $payload);
    }
}
