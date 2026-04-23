# vici-php

[![CI](https://github.com/bk203/vici-php/actions/workflows/ci.yml/badge.svg)](https://github.com/bk203/vici-php/actions/workflows/ci.yml)

A pure-PHP client implementation of strongSwan's [VICI protocol](https://github.com/strongswan/strongswan/blob/master/src/libcharon/plugins/vici/README.md). Use it from PHP to monitor, configure, and control the IKE daemon `charon`.

- Covers every command and event documented in the VICI README.
- Pluggable transport: Unix domain socket (default) or TCP, plus a generic `StreamTransport` for injection and testing.
- Blocking `Session` for commands, plus an `EventListener` for long-running event subscriptions.
- Streaming list commands (`list-sas`, `list-conns`, ...) expose event streams as PHP generators.
- Fully typed, PHPStan level 8 clean, zero runtime dependencies.

## Requirements

- PHP 8.4+
- A reachable charon VICI socket. Defaults to the Unix socket `/var/run/charon.vici`; TCP listeners are supported as well.

## Install

```bash
composer require bk203/vici-php
```

## Quick start

```php
use Bk203\Vici\Session;
use Bk203\Vici\Transport\UnixSocketTransport;

$session = new Session(new UnixSocketTransport('/var/run/charon.vici'));

$info = $session->version();
printf(
    "%s %s (%s, %s, %s)\n",
    $info['daemon'],
    $info['version'],
    $info['sysname'],
    $info['release'],
    $info['machine'],
);

$session->close();
```

If you're happy with the defaults, `new Session()` alone connects to `/var/run/charon.vici`.

### Connect over TCP

```php
use Bk203\Vici\Session;
use Bk203\Vici\Transport\TcpSocketTransport;

$session = new Session(new TcpSocketTransport(
    host: '10.0.0.1',
    port: 4502,
    connectTimeout: 5.0,
    readTimeout: 30.0,
));
```

### Inject a custom stream

```php
use Bk203\Vici\Session;
use Bk203\Vici\Transport\StreamTransport;

$stream = stream_socket_client('unix:///run/strongswan/charon.vici');
$session = new Session(new StreamTransport($stream, readTimeout: 10.0));
```

## Common workflows

### Load a connection

```php
$session->loadConn([
    'home' => [
        'version' => 2,
        'proposals' => ['aes256-sha256-modp2048'],
        'local_addrs' => ['192.0.2.1'],
        'remote_addrs' => ['198.51.100.1'],
        'local' => ['auth' => 'psk', 'id' => 'home@example.org'],
        'remote' => ['auth' => 'psk', 'id' => 'gw@example.org'],
        'children' => [
            'net' => [
                'local_ts' => ['10.0.0.0/24'],
                'remote_ts' => ['10.1.0.0/24'],
                'esp_proposals' => ['aes256-sha256'],
            ],
        ],
    ],
]);
```

Booleans are encoded as `yes` / `no`, integers and floats are stringified, and `null` values are skipped — matching the conventions used by the Python and Ruby reference clients.

### Stream active SAs

```php
foreach ($session->listSas() as $entry) {
    foreach ($entry as $name => $sa) {
        echo "{$name}: {$sa['state']} ({$sa['local-host']} -> {$sa['remote-host']})\n";
    }
}
```

Streaming commands return a `Generator`. If you break out early, the library automatically drains any remaining stream packets and unregisters the backing event before the next command can run, so the connection stays in sync.

### Initiate / terminate

```php
$session->initiate(['child' => 'net', 'timeout' => 30_000]);
$session->terminate(['ike' => 'home']);
```

Commands whose response carries `success = no` raise `CommandException` carrying the command name and the decoded response.

### Listen for events

```php
use Bk203\Vici\Event;
use Bk203\Vici\EventListener;

$listener = (new EventListener($session))
    ->on(Event::IKE_UPDOWN, function (string $name, array $message): void {
        $up = ($message['up'] ?? null) === 'yes' ? 'up' : 'down';
        echo "IKE {$up}: " . json_encode($message) . "\n";
    })
    ->on(Event::CHILD_UPDOWN, function (string $name, array $message): void {
        echo "Child event: " . json_encode($message) . "\n";
    })
    ->register([Event::IKE_UPDOWN, Event::CHILD_UPDOWN]);

try {
    $listener->listen(timeout: 60.0);
} finally {
    $listener->unregister();
}
```

A `Session` allows only one in-flight command at a time, so give a listener its own `Session` if you also want to issue commands concurrently from another thread/process. `$listener->next($timeout)` returns the next event (or `null` on timeout) if you'd rather poll than run a blocking loop.

## Command reference

All commands return decoded arrays (or generators for streaming variants). Every helper ultimately delegates to `Session::request()` / `Session::streamedRequest()`, so you can always fall back to the generic API for new commands:

```php
$response = $session->request('custom-command', ['arg' => 'value']);
```

| Category      | Methods |
| ------------- | ------- |
| Control       | `version()`, `stats()`, `reloadSettings()`, `initiate()`, `terminate()`, `rekey()`, `redirect()`, `install()`, `uninstall()` |
| Streaming     | `listSas()`, `listPolicies()`, `listConns()`, `listCerts()`, `listAuthorities()` |
| Configuration | `getConns()`, `getAuthorities()`, `loadConn()`, `unloadConn()`, `loadCert()`, `loadKey()`, `unloadKey()`, `getKeys()`, `loadToken()`, `loadShared()`, `unloadShared()`, `getShared()`, `flushCerts()`, `clearCreds()`, `loadAuthority()`, `unloadAuthority()`, `loadPool()`, `unloadPool()`, `getPools()` |
| Diagnostics   | `getAlgorithms()`, `getCounters()`, `resetCounters()` |

Event constants live on `Bk203\Vici\Event` (`LOG`, `CONTROL_LOG`, `LIST_SA`, `LIST_POLICY`, `LIST_CONN`, `LIST_CERT`, `LIST_AUTHORITY`, `IKE_UPDOWN`, `IKE_REKEY`, `IKE_UPDATE`, `CHILD_UPDOWN`, `CHILD_REKEY`, `ALERT`).

## Error handling

All exceptions extend `Bk203\Vici\Exception\ViciException`:

| Exception                    | Thrown when |
| ---------------------------- | ----------- |
| `ConnectionException`        | Underlying socket cannot connect, closes mid-stream, or `stream_select()` fails |
| `TimeoutException`           | Read/connect timeout elapses |
| `ProtocolException`          | Framing or message-encoding violation on the wire |
| `CommandUnknownException`    | Server replies with `CMD_UNKNOWN` |
| `CommandException`           | Command completes with `success = no`; exposes `->command` and `->response` |
| `EventRegistrationException` | Server replies with `EVENT_UNKNOWN` to `EVENT_REGISTER` / `EVENT_UNREGISTER` |

## Architecture

- `Bk203\Vici\Transport\TransportInterface` — 32-bit length-prefixed framing (max 512 KiB), implemented by `UnixSocketTransport`, `TcpSocketTransport`, and `StreamTransport`.
- `Bk203\Vici\Protocol\{Packet, PacketCodec, PacketType}` — packet layer: the 8-bit type + optional 8-bit-length name tag + optional message payload.
- `Bk203\Vici\Message\{MessageEncoder, MessageDecoder, ElementType}` — the hierarchical message tree: sections, key/value pairs (16-bit value length), and lists.
- `Bk203\Vici\Session` — the high-level API: command dispatch, event interleaving, reference-counted event (de-)registration, and typed wrappers for every VICI command.
- `Bk203\Vici\EventListener` — convenience layer over `Session` for long-running event subscriptions.

## Development

```bash
composer install
composer test            # PHPUnit (unit + integration, all in-process)
composer test:unit
composer test:integration
composer stan            # PHPStan level 8
composer cs              # PHP-CS-Fixer dry run
composer cs:fix          # PHP-CS-Fixer apply
```

Integration tests use an in-process `MockViciServer` backed by a socket pair, so no real charon daemon or container is required.

## License

MIT. See [LICENSE](LICENSE).

## Acknowledgements

Modelled after the reference [Python](https://github.com/strongswan/strongswan/tree/master/src/libcharon/plugins/vici/python) and [Go](https://github.com/strongswan/govici) VICI clients from the strongSwan project.
