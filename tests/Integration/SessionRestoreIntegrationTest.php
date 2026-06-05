<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Event;
use Bk203\Vici\Exception\ConnectionException;
use Bk203\Vici\Session;
use Bk203\Vici\Transport\ReconnectingTransport;
use PHPUnit\Framework\TestCase;

final class SessionRestoreIntegrationTest extends TestCase
{
    private FileSocketViciServer $server;
    private Session $session;

    protected function setUp(): void
    {
        $this->server = new FileSocketViciServer();

        $this->session = new Session(new ReconnectingTransport(
            path: $this->server->getPath(),
            connectTimeout: 2.0,
            readTimeout: 2.0,
            maxReconnectAttempts: 3,
            reconnectDelayMs: 50,
        ));
        $this->server->acceptClient();
    }

    protected function tearDown(): void
    {
        $this->session->close();
        $this->server->close();
    }

    public function testRestoreDaemonStateReplaysEventRegistrationAfterRestart(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

        $this->server->sendEventConfirm();
        $this->session->registerEvent(Event::LOG);
        $this->server->expectEventRegister(Event::LOG);

        $this->server->simulateRestart();

        $server = $this->server;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            $server->acceptClient(5.0);
            $server->expectEventRegister(Event::LOG);
            $server->sendEventConfirm();
            $server->expectCommand('version');
            $server->sendCmdResponse([
                'daemon' => 'charon',
                'version' => '5.9.13',
                'sysname' => 'Linux',
                'release' => '6.1.0',
                'machine' => 'x86_64',
            ]);
            exit(0);
        }

        $version = $this->session->version();
        pcntl_waitpid($pid, $status);

        self::assertSame('5.9.13', $version['version']);
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testRequestRetriesAfterMidCommandDisconnect(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

        $this->server->simulateRestart();

        $server = $this->server;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            $server->acceptClient(5.0);
            $server->expectCommand('version');
            $server->sendCmdResponse([
                'daemon' => 'charon',
                'version' => '5.9.14',
                'sysname' => 'Linux',
                'release' => '6.1.0',
                'machine' => 'x86_64',
            ]);
            exit(0);
        }

        $version = $this->session->version();
        pcntl_waitpid($pid, $status);

        self::assertSame('5.9.14', $version['version']);
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testEventDeliveryAfterRestore(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

        $received = [];
        $this->session->onEvent(Event::LOG, static function (string $name, array $msg) use (&$received): void {
            $received[] = [$name, $msg];
        });

        $this->server->sendEventConfirm();
        $this->session->registerEvent(Event::LOG);
        $this->server->expectEventRegister(Event::LOG);

        $this->server->simulateRestart();

        $server = $this->server;
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            $server->acceptClient(5.0);
            $server->expectEventRegister(Event::LOG);
            $server->sendEventConfirm();
            $server->expectCommand('version');
            $server->sendEvent(Event::LOG, [
                'group' => 'IKE',
                'level' => '1',
                'msg' => 'restored',
            ]);
            $server->sendCmdResponse([
                'daemon' => 'charon',
                'version' => '5.9.13',
                'sysname' => 'Linux',
                'release' => '6.1.0',
                'machine' => 'x86_64',
            ]);
            exit(0);
        }

        $version = $this->session->version();
        pcntl_waitpid($pid, $status);

        self::assertSame('5.9.13', $version['version']);
        self::assertCount(1, $received);
        self::assertSame(Event::LOG, $received[0][0]);
        self::assertSame('restored', $received[0][1]['msg']);
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testStreamedRequestDoesNotAutoResumeOnDisconnect(): void
    {
        $mock = new MockViciServer();
        $session = new Session($mock->getClientTransport());

        $gen = null;
        try {
            $mock->sendEventConfirm();
            $mock->sendEvent(Event::LIST_SA, ['gw' => ['uniqueid' => '1']]);

            $gen = $session->listSas();
            self::assertSame('1', $gen->current()['gw']['uniqueid']);

            $mock->simulateRestart();

            try {
                $gen->next();
                self::fail('Expected ConnectionException.');
            } catch (ConnectionException) {
            }
        } finally {
            unset($gen);
            $session->close();
            $mock->close();
        }
    }
}
