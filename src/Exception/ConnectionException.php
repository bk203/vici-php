<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown when the transport cannot connect to or communicate with the VICI socket.
 */
final class ConnectionException extends ViciException
{
    public function __construct(
        string $message,
        public readonly ?ConnectionFailureContext $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getDetailedMessage(): string
    {
        if ($this->context === null || $this->context->format() === '') {
            return $this->getMessage();
        }

        return $this->getMessage() . ' | ' . $this->context->format();
    }
}
