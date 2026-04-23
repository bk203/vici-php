<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Event;
use Bk203\Vici\EventListener;
use Bk203\Vici\Session;
use PHPUnit\Framework\TestCase;

final class EventListenerIntegrationTest extends TestCase
{
    private MockViciServer $server;
    private Session $session;

    protected function setUp(): void
    {
        $this->server = new MockViciServer();
        $this->session = new Session($this->server->getClientTransport());
    }

    protected function tearDown(): void
    {
        $this->session->close();
        $this->server->close();
    }

    public function testRegisterDispatchAndUnregister(): void
    {
        $this->server->sendEventConfirm();
        $this->server->sendEvent(Event::IKE_UPDOWN, [
            'up' => 'yes',
            'peer' => ['uniqueid' => '7'],
        ]);
        $this->server->sendEventConfirm();

        $received = [];
        $listener = new EventListener($this->session);
        $listener->on(Event::IKE_UPDOWN, static function (string $name, array $msg) use (&$received): void {
            $received[] = [$name, $msg];
        });
        $listener->register([Event::IKE_UPDOWN]);

        $listener->listen(
            timeout: 1.0,
            stopWhen: function () use (&$received): bool {
                return $received !== [];
            },
        );

        $listener->unregister();

        self::assertCount(1, $received);
        self::assertSame(Event::IKE_UPDOWN, $received[0][0]);
        self::assertSame('yes', $received[0][1]['up']);

        $this->server->expectEventRegister(Event::IKE_UPDOWN);
        $this->server->expectEventUnregister(Event::IKE_UPDOWN);
    }

    public function testListenRespectsTimeoutWhenNoEvents(): void
    {
        $listener = new EventListener($this->session);
        $start = microtime(true);
        $listener->listen(timeout: 0.2);
        $elapsed = microtime(true) - $start;

        self::assertGreaterThanOrEqual(0.15, $elapsed);
        self::assertLessThan(0.6, $elapsed);
    }

    public function testNextReturnsFirstEvent(): void
    {
        $this->server->sendEventConfirm();
        $this->server->sendEvent(Event::ALERT, ['type' => 'peer-auth-failed']);
        $this->server->sendEventConfirm();

        $listener = new EventListener($this->session);
        $listener->register([Event::ALERT]);
        $event = $listener->next(1.0);
        $listener->unregister();

        self::assertNotNull($event);
        self::assertSame(Event::ALERT, $event['name']);
        self::assertSame('peer-auth-failed', $event['message']['type']);

        $this->server->expectEventRegister(Event::ALERT);
        $this->server->expectEventUnregister(Event::ALERT);
    }

    public function testNextReturnsNullOnTimeout(): void
    {
        $listener = new EventListener($this->session);
        self::assertNull($listener->next(0.1));
    }
}
