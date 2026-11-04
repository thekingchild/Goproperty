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
use App\Platform\Operations\Scheduler\ScheduledTasks;
use Carbon\CarbonImmutable;

/**
 * A scheduled task whose last run failed, or that has not run 15 minutes
 * after it was due (PLATFORM_CONSOLE.md § 2 "Scheduler": "alert if a job
 * didn't run"). One incident per task. Not judged while the scheduler
 * itself is stopped: that is SchedulerStoppedCheck's one alert.
 */
final readonly class ScheduledTaskCheck implements AlertCheck
{
    public function __construct(private ScheduledTasks $tasks, private Heartbeats $heartbeats) {}

    public function rule(): AlertRule
    {
        return AlertRule::ScheduledTask;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $beat = $this->heartbeats->lastAt(Heartbeats::SCHEDULER);

        if ($beat === null || SchedulerStoppedCheck::stale($beat, $now, $settings)) {
            return [];
        }

        $failing = [];

        foreach ($this->tasks->all($now) as $task) {
            if ($task->failed()) {
                $failing[] = new AlertCondition(AlertRule::ScheduledTask, $task->name, "The scheduled task {$task->name} failed at ".Lagos::time($task->lastRunAt).($task->lastError !== null ? " ({$task->lastError})" : '').'. The error is in the application log.', ['task' => $task->name, 'result' => 'failed']);
            } elseif ($task->missedRunAt !== null) {
                $failing[] = new AlertCondition(AlertRule::ScheduledTask, $task->name, "The scheduled task {$task->name} was due at ".Lagos::time($task->missedRunAt).' and has not run since.', ['task' => $task->name, 'result' => 'missed']);
            }
        }

        return $failing;
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return "The scheduled task {$incident->subject} ran again.";
    }
}
