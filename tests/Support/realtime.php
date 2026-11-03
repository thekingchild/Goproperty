<?php

declare(strict_types=1);

use App\Hosting\Realtime;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Modules\BuildingGroup\Live\GroupChannel;

/*
 * Live updates on the VPS profile in tests (P07-03): the suite runs on the
 * shared profile (phpunit.xml), so a test that wants Reverb switches the
 * realtime driver and broadcaster here, and re-registers the modules'
 * channels on the new broadcaster (they are registered on the one in use
 * at boot).
 */

const REVERB_TEST_APP = ['id' => '100001', 'key' => 'gp-live-key', 'secret' => 'gp-live-secret'];

/** Switches this test to Reverb, the app reaching it at $host:$port. */
function useReverb(string $host = '127.0.0.1', int $port = 8080, string $scheme = 'http'): void
{
    config([
        'hosting.realtime.driver' => 'reverb',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => REVERB_TEST_APP['id'],
        'broadcasting.connections.reverb.key' => REVERB_TEST_APP['key'],
        'broadcasting.connections.reverb.secret' => REVERB_TEST_APP['secret'],
        'broadcasting.connections.reverb.options.host' => $host,
        'broadcasting.connections.reverb.options.port' => $port,
        'broadcasting.connections.reverb.options.scheme' => $scheme,
        'broadcasting.connections.reverb.options.useTLS' => $scheme === 'https',
        // Where browsers would connect: the public host, through the proxy.
        'hosting.realtime.client.host' => 'ws.goproperty.test',
        'hosting.realtime.client.port' => 443,
        'hosting.realtime.client.scheme' => 'https',
    ]);

    app()->forgetInstance(Realtime::class);
    app(BroadcastManager::class)->forgetDrivers();
    GroupChannel::register();
}

/** A TCP port nothing listens on now. */
function freeTcpPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name = stream_socket_get_name($server, false);
    fclose($server);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** Starts `php artisan reverb:start` on $port with the test app's keys and waits until it listens. */
function startReverb(int $port): InvokedProcess
{
    $process = Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'REVERB_APP_ID' => REVERB_TEST_APP['id'],
        'REVERB_APP_KEY' => REVERB_TEST_APP['key'],
        'REVERB_APP_SECRET' => REVERB_TEST_APP['secret'],
        'REVERB_ALLOWED_ORIGINS' => '127.0.0.1',
        'REVERB_APP_RATE_LIMITING_ENABLED' => 'false',
    ])->start([PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', "--port={$port}", '--no-interaction']);

    $deadline = microtime(true) + 20;

    while (microtime(true) < $deadline) {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.2);

        if ($socket !== false) {
            fclose($socket);

            return $process;
        }

        if (! $process->running()) {
            throw new RuntimeException('Reverb did not start: '.$process->latestErrorOutput().$process->latestOutput());
        }

        usleep(100_000);
    }

    $process->stop();

    throw new RuntimeException('Reverb did not listen on port '.$port.' within 20 seconds.');
}
