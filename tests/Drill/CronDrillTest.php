<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Fixtures\Jobs\RecordWorkerEnvironment;

/*
 * P00-03 acceptance: the cron line alone, on a host that disables `exec`,
 * `proc_open` and pcntl, runs queued work (INSTALLER.md § 8). This starts
 * `php artisan schedule:run` as the cron would, with those functions
 * disabled, against this test's database.
 */

// The functions shared hosts commonly disable. pcntl's extension stays
// loaded, which is the case that breaks Laravel's scheduler unguarded.
const SHARED_HOST_DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,'
    .'pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals';

afterEach(function () {
    foreach (['jobs', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
        DB::table($table)->delete();
    }
});

it('runs a queued job from cron with exec and proc_open disabled', function () {
    $token = Str::random(16);
    RecordWorkerEnvironment::dispatch($token)->onConnection('database')->onQueue('notifications');

    $database = DB::connection();

    $cron = Process::path(base_path())
        ->timeout(90)
        ->env([
            'APP_ENV' => 'testing',
            'HOSTING_PROFILE' => 'shared',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'DB_CONNECTION' => $database->getName(),
            'DB_HOST' => (string) $database->getConfig('host'),
            'DB_PORT' => (string) $database->getConfig('port'),
            'DB_DATABASE' => $database->getDatabaseName(),
            'DB_USERNAME' => (string) $database->getConfig('username'),
            'DB_PASSWORD' => (string) $database->getConfig('password'),
        ])
        ->run([PHP_BINARY, '-d', 'disable_functions='.SHARED_HOST_DISABLED_FUNCTIONS, 'artisan', 'schedule:run', '--no-ansi']);

    expect($cron->successful())->toBeTrue($cron->output().$cron->errorOutput())
        ->and($cron->output())->toContain('queue-tick');

    $record = Cache::get('drill:'.$token);

    expect($record)->toBeArray('The cron process did not run the queued job.')
        ->and($record)->toMatchArray(['exec' => false, 'proc_open' => false, 'pcntl_signal' => false])
        ->and($record['pid'])->not->toBe(getmypid())
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $heartbeats = app(Heartbeats::class);

    // At least this drill's job: a Schedule::job that is due in the same
    // minute (e.g. hourlyAt(20)) is queued by the same cron run and drained too.
    $tick = $heartbeats->last(Heartbeats::QUEUE_TICK);

    expect($heartbeats->lastAt(Heartbeats::SCHEDULER))->not->toBeNull()
        ->and($tick)->toMatchArray(['stopped_by' => 'drained'])
        ->and($tick['total'] ?? 0)->toBeGreaterThanOrEqual(1);
});
