<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown on protocol-level violations (malformed packets, impossible lengths,
 * unexpected message types, segment size overflow, ...).
 */
final class ProtocolException extends ViciException
{
}
