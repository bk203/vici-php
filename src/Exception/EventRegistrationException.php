<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown when the server rejects an event (de-)registration (EVENT_UNKNOWN).
 */
final class EventRegistrationException extends ViciException
{
    public function __construct(
        string $message,
        public readonly string $event,
    ) {
        parent::__construct($message);
    }
}
