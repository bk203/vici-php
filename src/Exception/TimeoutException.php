<?php

declare(strict_types=1);

namespace Bk203\Vici\Exception;

/**
 * Thrown when a configured I/O or listen timeout elapses before the
 * expected data / response arrived.
 */
final class TimeoutException extends ViciException
{
}
