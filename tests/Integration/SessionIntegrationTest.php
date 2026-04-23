<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Event;
use Bk203\Vici\Exception\CommandException;
use Bk203\Vici\Exception\CommandUnknownException;
use Bk203\Vici\Exception\EventRegistrationException;
use Bk203\Vici\Session;
use PHPUnit\Framework\TestCase;

final class SessionIntegrationTest extends TestCase
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

    public function testVersion(): void
    {
        $this->server->sendCmdResponse([
            'daemon' => 'charon',
            'version' => '5.9.13',
            'sysname' => 'Linux',
            'release' => '6.1.0',
            'machine' => 'x86_64',
        ]);

        $version = $this->session->version();

        self::assertSame('charon', $version['daemon']);
        self::assertSame('5.9.13', $version['version']);
        self::assertSame([], $this->server->expectCommand('version'));
    }

    public function testCommandUnknown(): void
    {
        $this->server->sendCmdUnknown();

        $this->expectException(CommandUnknownException::class);
        try {
            $this->session->request('nope');
        } finally {
            $this->server->expectCommand('nope');
        }
    }

    public function testRequireSuccessFailure(): void
    {
        $this->server->sendCmdResponse([
            'success' => 'no',
            'errmsg' => 'config not found',
        ]);

        try {
            $this->session->loadConn(['peer' => ['local' => ['auth' => 'psk']]]);
            self::fail('Expected CommandException.');
        } catch (CommandException $e) {
            self::assertSame('config not found', $e->getMessage());
            self::assertSame('load-conn', $e->command);
            self::assertSame('no', $e->response['success']);
        }

        $args = $this->server->expectCommand('load-conn');
        self::assertArrayHasKey('peer', $args);
    }

    public function testLoadConnSuccess(): void
    {
        $this->server->sendCmdResponse(['success' => 'yes']);

        $response = $this->session->loadConn([
            'peer' => [
                'version' => 2,
                'proposals' => ['aes256-sha256-modp2048'],
                'local' => ['auth' => 'psk', 'id' => 'peer@example.org'],
            ],
        ]);

        self::assertSame('yes', $response['success']);
        $args = $this->server->expectCommand('load-conn');
        self::assertSame('2', $args['peer']['version']);
        self::assertSame(['aes256-sha256-modp2048'], $args['peer']['proposals']);
        self::assertSame('psk', $args['peer']['local']['auth']);
    }

    public function testStreamedListSas(): void
    {
        $this->server->sendEventConfirm();
        $this->server->sendEvent(Event::LIST_SA, [
            'gw' => [
                'uniqueid' => '1',
                'state' => 'ESTABLISHED',
            ],
        ]);
        $this->server->sendEvent(Event::LIST_SA, [
            'gw' => [
                'uniqueid' => '2',
                'state' => 'ESTABLISHED',
            ],
        ]);
        $this->server->sendCmdResponse();
        $this->server->sendEventConfirm();

        $collected = [];
        foreach ($this->session->listSas() as $ev) {
            $collected[] = $ev;
        }

        self::assertCount(2, $collected);
        self::assertSame('1', $collected[0]['gw']['uniqueid']);
        self::assertSame('2', $collected[1]['gw']['uniqueid']);

        $this->server->expectEventRegister(Event::LIST_SA);
        $this->server->expectCommand('list-sas');
        $this->server->expectEventUnregister(Event::LIST_SA);
    }

    public function testStreamedRequestUnregistersOnEarlyBreak(): void
    {
        $this->server->sendEventConfirm();
        $this->server->sendEvent(Event::LIST_SA, ['gw' => ['uniqueid' => '1']]);
        $this->server->sendEvent(Event::LIST_SA, ['gw' => ['uniqueid' => '2']]);
        $this->server->sendCmdResponse();
        $this->server->sendEventConfirm();

        $gen = $this->session->listSas();
        $first = $gen->current();
        self::assertSame('1', $first['gw']['uniqueid']);

        unset($gen);

        $this->server->expectEventRegister(Event::LIST_SA);
        $this->server->expectCommand('list-sas');
        $this->server->expectEventUnregister(Event::LIST_SA);
    }

    public function testEventInterleavedWithCommand(): void
    {
        $this->server->sendEventConfirm();
        $this->server->sendEvent(Event::LOG, [
            'group' => 'IKE',
            'level' => '1',
            'msg' => 'hello',
        ]);
        $this->server->sendCmdResponse([
            'daemon' => 'charon',
            'version' => '5.9.13',
            'sysname' => 'Linux',
            'release' => '6.1.0',
            'machine' => 'x86_64',
        ]);

        $received = [];
        $this->session->onEvent(Event::LOG, static function (string $name, array $msg) use (&$received): void {
            $received[] = [$name, $msg];
        });

        $this->session->registerEvent(Event::LOG);

        $version = $this->session->version();

        self::assertSame('5.9.13', $version['version']);
        self::assertCount(1, $received);
        self::assertSame(Event::LOG, $received[0][0]);
        self::assertSame('hello', $received[0][1]['msg']);

        $this->server->expectEventRegister(Event::LOG);
        $this->server->expectCommand('version');
    }

    public function testEventRegistrationUnknownThrows(): void
    {
        $this->server->sendEventUnknown();

        $this->expectException(EventRegistrationException::class);
        try {
            $this->session->registerEvent('no-such-event');
        } finally {
            $this->server->expectEventRegister('no-such-event');
        }
    }
}
