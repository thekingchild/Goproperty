<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Checks;

use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertCondition;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Queues\QueueHealth;
use Carbon\CarbonImmutable;

/** A queue whose oldest due job has waited longer than `operations.alert_queue_wait_minutes` (default 5; PLATFORM_CONSOLE.md § 2). One incident per queue. */
final readonly class QueueWaitCheck implements AlertCheck
{
    public function __construct(private QueueHealth $queues) {}

    public function rule(): AlertRule
    {
        return AlertRule::QueueWait;
    }

    public function failing(CarbonImmutable $now, AlertSettings $settings): array
    {
        $failing = [];

        foreach ($this->queues->figures($now) as $queue) {
            $wait = $queue->waitSeconds($now);

            if ($wait !== null && $wait > $settings->queueWaitMinutes * 60) {
                $failing[] = new AlertCondition(AlertRule::QueueWait, $queue->queue, ($queue->waiting === 1 ? '1 job is' : "{$queue->waiting} jobs are")." waiting on the {$queue->queue} queue; the oldest for more than {$settings->queueWaitMinutes} minutes.", ['queue' => $queue->queue, 'waiting' => $queue->waiting, 'wait_seconds' => $wait]);
            }
        }

        return $failing;
    }

    public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
    {
        return "The {$incident->subject} queue is moving again.";
    }
}
