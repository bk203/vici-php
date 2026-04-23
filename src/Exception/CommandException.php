<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown when a VICI command completes but returns a non-success reply
 * (typically `success = no` with an `errmsg`).
 */
final class CommandException extends ViciException
{
    /**
     * @param array<string, mixed> $response Full decoded response message.
     */
    public function __construct(
        string $message,
        public readonly string $command,
        public readonly array $response,
    ) {
        parent::__construct($message);
    }
}
