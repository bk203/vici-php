<?php

declare(strict_types=1);

namespace Bk203\Vici\Tests\Integration;

use Bk203\Vici\Session;
use Bk203\Vici\Transport\ReconnectingTransport;
use PHPUnit\Framework\TestCase;

final class ReconnectingTransportIntegrationTest extends TestCase
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

    public function testVersionSurvivesSimulatedServerRestart(): void
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
        self::assertSame([], $this->server->expectCommand('version'));

        $this->server->simulateRestart();

        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to accept during reconnect.');
        }

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
}
