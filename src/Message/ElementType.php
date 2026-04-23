<?php

declare(strict_types=1);

namespace Bk203\Vici\Message;

/**
 * Message element type identifiers as defined by the VICI protocol.
 *
 * @see https://github.com/strongswan/strongswan/blob/master/src/libcharon/plugins/vici/README.md
 */
enum ElementType: int
{
    case SECTION_START = 1;
    case SECTION_END = 2;
    case KEY_VALUE = 3;
    case LIST_START = 4;
    case LIST_ITEM = 5;
    case LIST_END = 6;
}
