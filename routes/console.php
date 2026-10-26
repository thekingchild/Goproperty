<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuthenticationLog;
use App\Hosting\Heartbeats;
use App\Hosting\Queue\QueueTick;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| One cron line runs `schedule:run` every minute (INSTALLER.md § 8). Register
| work with Schedule::call() or Schedule::job() only: those run inside the
| cron's PHP process. Schedule::command() and runInBackground() start a shell
| through proc_open, which shared hosts may disable (tested in
| tests/Feature/Hosting/ScheduleTest.php).
|
*/

Schedule::call(fn (Heartbeats $heartbeats) => $heartbeats->beat(Heartbeats::SCHEDULER))
    ->name('scheduler-heartbeat')
    ->everyMinute();

// The audit log's hash chain is walked nightly; a break is a critical log
// line for whoever watches the logs (PLATFORM_CONSOLE.md § 7).
Schedule::call(function (AuditChain $chain): void {
    $result = $chain->verify();

    if (! $result->intact) {
        Log::critical('The audit log hash chain is broken.', ['entry' => $result->brokenAtId, 'reason' => $result->reason, 'intact_before' => $result->checked]);
    }
})->name('audit-chain-verify')->dailyAt('02:30');

// Authentication log retention: one year (PLATFORM_CONSOLE.md § 8).
Schedule::call(fn () => AuthenticationLog::query()->where('created_at', '<', now()->subYear())->delete())
    ->name('authentication-log-prune')
    ->dailyAt('02:45');

// Keep this last: it can use most of the minute, and events run in order.
Schedule::call(fn (QueueTick $tick) => $tick->run())
    ->name('queue-tick')
    ->everyMinute()
    ->when(fn (): bool => (bool) config('hosting.queue_tick.enabled'));
