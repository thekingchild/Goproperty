<?php

declare(strict_types=1);

use App\Audit\AuditChain;
use App\Audit\AuthenticationLog;
use App\Backup\BackupRunner;
use App\Backup\BackupVerifier;
use App\Hosting\Heartbeats;
use App\Hosting\Queue\QueueTick;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Compliance\DocumentExpiryCheck;
use App\Platform\Impersonation\Impersonations;
use App\Sms\Credits\GrantSmsAllowances;
use App\Subscriptions\Jobs\EndTrials;
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

// Backups (PLATFORM_CONSOLE.md § 4): the database and the uploaded files are
// separate jobs, in-process (no exec), each to every destination that takes it.
Schedule::call(fn (BackupRunner $runner) => $runner->run('db'))->name('backup-db')->dailyAt('01:30');
Schedule::call(fn (BackupRunner $runner) => $runner->run('files'))->name('backup-files')->dailyAt('02:30');

// Monthly proof that the newest database backup restores (needs
// BACKUP_VERIFY_DB_DATABASE); a failure is a critical log line.
Schedule::call(function (BackupVerifier $verifier): void {
    $result = $verifier->verify();

    if (! $result['ok']) {
        Log::critical('The monthly backup verify failed: '.$result['message']);
    }
})->name('backup-verify')->monthlyOn(1, '04:00');

// Two-person requests nobody decided in time (PLATFORM_CONSOLE.md § 1).
Schedule::call(fn (ApprovalService $approvals) => $approvals->expireStale())
    ->name('approvals-expire')
    ->everyFifteenMinutes();

// Support sessions past their 30 minutes, or whose 60-second hand-off link
// ran out unused, end with `timeout` (PLATFORM_CONSOLE.md § 6). Each
// customer request checks the limit too; this closes the row on time.
Schedule::call(fn (Impersonations $impersonations) => $impersonations->endExpired())
    ->name('impersonation-timeout')
    ->everyMinute();

// Transaction Tier documents near or past their expiry date: flagged once
// per stage, owners emailed (ONBOARDING_COMPLIANCE.md § 5.3–5.4).
Schedule::call(fn (DocumentExpiryCheck $check) => $check->run())
    ->name('compliance-document-expiry')
    ->dailyAt('03:15');

// SMS credits (P05-03): this Lagos month's allowance for every organization
// not cancelled, last month's unused one expired; once a month each, made up
// within the hour after a missed run.
Schedule::job(new GrantSmsAllowances, 'billing')->name('sms-credits-allowance')->hourlyAt(5);

// Subscriptions (P06-01b): trials whose end has passed become active (the
// first billing period starts); hourly, so a missed run is made up.
Schedule::job(new EndTrials, 'billing')->name('subscriptions-trials')->hourlyAt(15);

// Keep this last: it can use most of the minute, and events run in order.
Schedule::call(fn (QueueTick $tick) => $tick->run())
    ->name('queue-tick')
    ->everyMinute()
    ->when(fn (): bool => (bool) config('hosting.queue_tick.enabled'));
