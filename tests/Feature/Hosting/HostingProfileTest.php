<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use App\Hosting\HostingCheck;
use App\Hosting\HostingProfile;
use App\Hosting\Realtime;
use App\Hosting\RealtimeDriver;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Laravel\Reverb\ReverbServiceProvider;

const DRIVER_VARIABLES = [
    'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'BROADCAST_CONNECTION',
    'PDF_DRIVER', 'REALTIME_DRIVER', 'QUEUE_TICK_ENABLED',
];

/**
 * Loads a config file as it would be cached under the given environment.
 *
 * @param  array<string, string|null>  $env  null removes the variable
 * @return array<string, mixed>
 */
function configFileUnder(string $file, array $env): array
{
    $saved = [];

    foreach ($env as $name => $value) {
        $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        setEnvironmentVariable($name, $value);
    }

    try {
        return require config_path($file);
    } finally {
        foreach ($saved as $name => [$env, $server, $process]) {
            setEnvironmentVariable($name, is_string($process) ? $process : (is_string($env) ? $env : (is_string($server) ? $server : null)));
        }
    }
}

function setEnvironmentVariable(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        return;
    }

    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}

/** @return array<string, mixed> */
function driversFor(?string $profile, array $overrides = []): array
{
    $env = ['HOSTING_PROFILE' => $profile, ...array_fill_keys(DRIVER_VARIABLES, null), ...$overrides];
    $hosting = configFileUnder('hosting.php', $env);

    return [
        'queue' => configFileUnder('queue.php', $env)['default'],
        'cache' => configFileUnder('cache.php', $env)['default'],
        'session' => configFileUnder('session.php', $env)['driver'],
        'broadcast' => configFileUnder('broadcasting.php', $env)['default'],
        'pdf' => configFileUnder('pdf.php', $env)['driver'],
        'realtime' => $hosting['realtime']['driver'],
        'tick' => $hosting['queue_tick']['enabled'],
    ];
}

it('picks every driver from the hosting profile', function (?string $profile, array $expected) {
    expect(driversFor($profile))->toBe($expected);
})->with([
    'shared' => ['shared', [
        'queue' => 'database', 'cache' => 'database', 'session' => 'database', 'broadcast' => 'null',
        'pdf' => 'dompdf', 'realtime' => 'polling', 'tick' => true,
    ]],
    'unset means shared' => [null, [
        'queue' => 'database', 'cache' => 'database', 'session' => 'database', 'broadcast' => 'null',
        'pdf' => 'dompdf', 'realtime' => 'polling', 'tick' => true,
    ]],
    'vps' => ['vps', [
        'queue' => 'redis', 'cache' => 'redis', 'session' => 'redis', 'broadcast' => 'reverb',
        'pdf' => 'chrome', 'realtime' => 'reverb', 'tick' => false,
    ]],
]);

it('lets a driver variable override the profile', function () {
    $drivers = driversFor('vps', ['QUEUE_CONNECTION' => 'database', 'QUEUE_TICK_ENABLED' => 'true', 'PDF_DRIVER' => 'dompdf']);

    expect($drivers)->toMatchArray(['queue' => 'database', 'tick' => true, 'pdf' => 'dompdf', 'cache' => 'redis']);
});

it('runs this suite on the shared-hosting profile', function () {
    // CI and local runs must exercise the drivers shared hosting uses (P00-03).
    expect(HostingProfile::current())->toBe(HostingProfile::Shared)
        ->and(config('cache.default'))->toBe('database')
        ->and(config('session.driver'))->toBe('database')
        ->and(config('broadcasting.default'))->toBe('null')
        ->and(config('pdf.driver'))->toBe('dompdf')
        ->and(app(Realtime::class)->usesWebsockets())->toBeFalse()
        ->and(config('hosting.queue_tick.enabled'))->toBeTrue();
});

it('rejects an unknown profile', function () {
    config(['hosting.profile' => 'cloud']);

    HostingProfile::current();
})->throws(InvalidArgumentException::class, 'HOSTING_PROFILE must be "shared" or "vps"');

it('polls on shared hosting and keeps a slower poll behind Reverb', function (string $driver, bool $websockets, int $seconds) {
    config([
        'hosting.realtime.driver' => $driver,
        'hosting.realtime.poll_seconds' => 15,
        'hosting.realtime.fallback_poll_seconds' => 60,
    ]);

    $realtime = Realtime::fromConfig(config());

    expect($realtime->driver)->toBe(RealtimeDriver::from($driver))
        ->and($realtime->usesWebsockets())->toBe($websockets)
        ->and($realtime->pollSeconds())->toBe($seconds);
})->with([
    'polling' => ['polling', false, 15],
    'reverb' => ['reverb', true, 60],
]);

describe('configuration check', function () {
    beforeEach(function () {
        // Tests run jobs inline; on a real host jobs go to the database queue.
        config(['queue.default' => 'database']);
    });

    it('passes the shared-hosting defaults', function () {
        expect(app(HostingCheck::class)->problems())->toBe([]);

        $this->artisan('goproperty:hosting')
            ->expectsOutputToContain('Shared hosting (Profile A)')
            ->expectsOutputToContain('Hosting configuration is consistent.')
            ->assertSuccessful();
    });

    it('flags settings that cannot work together', function (array $settings, string $problem) {
        config($settings);

        expect(implode("\n", app(HostingCheck::class)->problems()))->toContain($problem);

        $this->artisan('goproperty:hosting')->assertFailed();
    })->with([
        'unknown profile' => [['hosting.profile' => 'cloud'], 'HOSTING_PROFILE must be'],
        'no tick on shared hosting' => [['hosting.queue_tick.enabled' => false], 'nothing will run queued jobs'],
        'jobs pushed elsewhere' => [['queue.default' => 'sync'], 'Jobs are pushed to "sync" but the queue tick drains "database"'],
        'tick on a non-database queue' => [['hosting.queue_tick.connection' => 'sync'], 'drains a "database" connection only'],
        'reverb without broadcasting' => [['hosting.realtime.driver' => 'reverb'], 'REALTIME_DRIVER=reverb needs BROADCAST_CONNECTION=reverb'],
        'reverb without its keys' => [['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => 'ws.example.com'], 'BROADCAST_CONNECTION=reverb needs REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET.'],
        'reverb without its host' => [['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.app_id' => '1', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.options.host' => null], 'BROADCAST_CONNECTION=reverb needs REVERB_HOST.'],
        'reverb with no host for browsers' => [['hosting.realtime.driver' => 'reverb', 'broadcasting.default' => 'reverb', 'hosting.realtime.client.host' => null], 'where browsers reach Reverb'],
        'chrome without Gotenberg' => [['pdf.driver' => 'chrome', 'pdf.chrome.url' => null], 'PDF_DRIVER=chrome needs PDF_CHROME_URL'],
        'unknown PDF driver' => [['pdf.driver' => 'wkhtmltopdf'], 'PDF_DRIVER must be "dompdf" or "chrome"'],
    ]);

    it('accepts a VPS that has Horizon instead of the tick', function () {
        config(['hosting.profile' => 'vps', 'hosting.queue_tick.enabled' => false]);

        expect(app(HostingCheck::class)->problems())->toBe([]);
    });

    it('accepts a VPS with Reverb set up, Reverb\'s server installed with the app (P07-03)', function () {
        config([
            'hosting.profile' => 'vps', 'hosting.queue_tick.enabled' => false,
            'hosting.realtime.driver' => 'reverb', 'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => '1', 'broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1', 'hosting.realtime.client.host' => 'ws.example.com',
        ]);

        expect(app(HostingCheck::class)->problems())->toBe([])
            ->and(class_exists(ReverbServiceProvider::class))->toBeTrue()
            ->and(array_keys(Artisan::all()))->toContain('reverb:start');
    });

    it('keeps Reverb to this site\'s pages and to listening only (P07-03)', function () {
        $reverb = configFileUnder('reverb.php', ['APP_URL' => 'https://app.goproperty.ng', 'REVERB_ALLOWED_ORIGINS' => null, 'REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM' => null, 'REVERB_SERVER_HOST' => null]);
        $app = $reverb['apps']['apps'][0];

        expect($app['allowed_origins'])->toBe(['app.goproperty.ng'])
            ->and($app['accept_client_events_from'])->toBe('none')
            ->and($reverb['servers']['reverb']['host'])->toBe('127.0.0.1')
            ->and(configFileUnder('reverb.php', ['REVERB_ALLOWED_ORIGINS' => 'app.goproperty.ng, *.goproperty.ng'])['apps']['apps'][0]['allowed_origins'])->toBe(['app.goproperty.ng', '*.goproperty.ng'])
            ->and(configFileUnder('broadcasting.php', [])['connections']['reverb']['client_options'])->toBe(['connect_timeout' => 2.0, 'timeout' => 3.0]);
    });
});

it('still reports when the cache cannot be read yet', function () {
    // Deploys run the check before migrations, when the cache table may not exist.
    config(['queue.default' => 'database']);
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('get')->andThrow(new RuntimeException("Table 'cache' doesn't exist"));
    $this->app->instance(Heartbeats::class, new Heartbeats($cache));

    $this->artisan('goproperty:hosting')
        ->expectsOutputToContain('unavailable (cache not readable)')
        ->assertSuccessful();
});
