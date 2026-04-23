<?php

declare(strict_types=1);

namespace Bk203\Vici\Protocol;

/**
 * Packet type identifiers as defined by the VICI protocol.
 *
 * @see https://github.com/strongswan/strongswan/blob/master/src/libcharon/plugins/vici/README.md
 */
enum PacketType: int
{
    case CMD_REQUEST = 0;
    case CMD_RESPONSE = 1;
    case CMD_UNKNOWN = 2;
    case EVENT_REGISTER = 3;
    case EVENT_UNREGISTER = 4;
    case EVENT_CONFIRM = 5;
    case EVENT_UNKNOWN = 6;
    case EVENT = 7;

    public function hasName(): bool
    {
        return match ($this) {
            self::CMD_REQUEST,
            self::EVENT_REGISTER,
            self::EVENT_UNREGISTER,
            self::EVENT => true,
            self::CMD_RESPONSE,
            self::CMD_UNKNOWN,
            self::EVENT_CONFIRM,
            self::EVENT_UNKNOWN => false,
        };
    }

    public function hasMessage(): bool
    {
        return match ($this) {
            self::CMD_REQUEST,
            self::CMD_RESPONSE,
            self::EVENT => true,
            self::CMD_UNKNOWN,
            self::EVENT_REGISTER,
            self::EVENT_UNREGISTER,
            self::EVENT_CONFIRM,
            self::EVENT_UNKNOWN => false,
        };
    }
}
