<?php

declare(strict_types=1);

namespace Bk203\Vici\Transport;

/**
 * Transport that can re-establish a VICI connection after the socket is closed
 * or recreated. Implementations may invoke an optional callback after each
 * successful reconnect so higher layers can restore daemon-side session state.
 */
interface ReconnectableTransportInterface extends TransportInterface
{
    public function reconnect(): void;

    /**
     * @param callable(): void|null $callback
     */
    public function setOnReconnect(?callable $callback): void;
}
