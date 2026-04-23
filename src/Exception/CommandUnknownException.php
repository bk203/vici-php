<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown when the VICI server replies with CMD_UNKNOWN, indicating the
 * requested command is not implemented by the daemon.
 */
final class CommandUnknownException extends ViciException
{
}
