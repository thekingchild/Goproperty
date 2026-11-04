<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Hosting\Heartbeats;
use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Lagos;
use App\Platform\Operations\Alerts\OperationsAlert;
use Carbon\CarbonImmutable;

/**
 * The scheduler has stopped when its heartbeat (written first thing every
 * minute by `scheduler-heartbeat`) is older than the configured window
 * (`operations.alert_scheduler_minutes`, default 5). A heartbeat never
 * written is not judged: a new installation's cron check belongs to the
 * installer (INSTALLER.md § 5). This check cannot rely on the scheduler, so
 * the watchdog runs it from web requests and the uptime link too
 * (PLATFORM_CONSOLE.md § 2 "Who notices a stopped scheduler").
 */
final readonly class SchedulerStoppedCheck implements AlertCheck
{
    public function __construct(private Heartbeats $heartbeats) {}

    public function rule(): AlertRule
    {
        return AlertRule::SchedulerStopped;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $at = $this->heartbeats->lastAt(Heartbeats::SCHEDULER);

        if ($at === null || ! self::stale($at, $now, $settings)) {
            return [];
        }

        return [new AlertCondition(
            AlertRule::SchedulerStopped,
            '',
            'The scheduler has not run since '.Lagos::time($at).' (more than '.$settings->schedulerMinutes.' minutes). Reminders, bills, backups and the queue on shared hosting wait for it: check the cron line on the server (Operations → System operations shows it).',
            ['last_run_at' => $at->toIso8601String(), 'window_minutes' => $settings->schedulerMinutes],
        )];
    }

    public static function stale(CarbonImmutable $at, CarbonImmutable $now, AlertSettings $settings): bool
    {
        return $at->diffInSeconds($now, true) > $settings->schedulerMinutes * 60;
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        $at = $this->heartbeats->lastAt(Heartbeats::SCHEDULER);

        return 'The scheduler is running again'.($at !== null ? ' (last run '.Lagos::time($at).')' : '').'.';
    }
}
