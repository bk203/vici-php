<?php

declare(strict_types=1);

namespace Bk203\Vici\Protocol;

use Bk203\Vici\Exception\ProtocolException;

/**
 * Immutable VICI packet value object.
 *
 * Depending on the {@see PacketType} a packet may carry an optional name
 * (ASCII identifier for commands/events) and/or an optional encoded message
 * payload (raw bytes as produced by {@see \Bk203\Vici\Message\MessageEncoder}).
 */
final readonly class Packet
{
    public function __construct(
        public PacketType $type,
        public ?string $name = null,
        public string $payload = '',
    ) {
        if ($this->type->hasName() && ($this->name === null || $this->name === '')) {
            throw new ProtocolException(\sprintf(
                'Packet type %s requires a non-empty name.',
                $this->type->name,
            ));
        }
        if (!$this->type->hasName() && $this->name !== null) {
            throw new ProtocolException(\sprintf(
                'Packet type %s must not carry a name.',
                $this->type->name,
            ));
        }
        if (!$this->type->hasMessage() && $this->payload !== '') {
            throw new ProtocolException(\sprintf(
                'Packet type %s must not carry a message payload.',
                $this->type->name,
            ));
        }
    }

    public static function cmdRequest(string $name, string $payload = ''): self
    {
        return new self(PacketType::CMD_REQUEST, $name, $payload);
    }

    public static function cmdResponse(string $payload = ''): self
    {
        return new self(PacketType::CMD_RESPONSE, null, $payload);
    }

    public static function cmdUnknown(): self
    {
        return new self(PacketType::CMD_UNKNOWN);
    }

    public static function eventRegister(string $event): self
    {
        return new self(PacketType::EVENT_REGISTER, $event);
    }

    public static function eventUnregister(string $event): self
    {
        return new self(PacketType::EVENT_UNREGISTER, $event);
    }

    public static function eventConfirm(): self
    {
        return new self(PacketType::EVENT_CONFIRM);
    }

    public static function eventUnknown(): self
    {
        return new self(PacketType::EVENT_UNKNOWN);
    }

    public static function event(string $name, string $payload = ''): self
    {
        return new self(PacketType::EVENT, $name, $payload);
    }
}
