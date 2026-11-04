<?php

declare(strict_types=1);

namespace App\Platform\Filament\Widgets\Operations;

use App\Hosting\Heartbeats;
use App\Installer\CronLine;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Scheduler\ScheduledTasks;
use App\Platform\Staff\StaffPermission as P;
use Carbon\CarbonImmutable;

/**
 * Scheduler (PLATFORM_CONSOLE.md § 2): the scheduler's and the queue tick's
 * heartbeats, every scheduled task's last run, result and next run, and the
 * cron line for this server (only for `system.status`: it names the
 * server's paths).
 */
final class SchedulerWidget extends OperationsWidget
{
    protected static ?int $sort = 4;

    protected string $view = 'console.operations.scheduler';

    public static function canView(): bool
    {
        return self::allowed(P::OperationsView, P::SystemStatus);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $heartbeats = app(Heartbeats::class);
        $now = CarbonImmutable::now();

        return [
            'now' => $now,
            'window' => AlertSettings::current()->schedulerMinutes,
            'scheduler' => $heartbeats->lastAt(Heartbeats::SCHEDULER),
            'tick' => $heartbeats->last(Heartbeats::QUEUE_TICK),
            'tickAt' => $heartbeats->lastAt(Heartbeats::QUEUE_TICK),
            'tickEnabled' => (bool) config('hosting.queue_tick.enabled'),
            'tasks' => app(ScheduledTasks::class)->all($now),
            'cronLine' => self::allowed(P::SystemStatus) ? app(CronLine::class)->line(base_path()) : null,
        ];
    }
}
