<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts;

use App\Enums\Concerns\HasValues;

/**
 * What the operations alerts watch (PLATFORM_CONSOLE.md § 2 "Alerts";
 * docs/plans/P08-subtasks.md defaults 29–49). Each rule is one check
 * (Checks\*) that says which of its subjects are failing right now; the
 * monitor turns that into incidents and notices.
 */
enum AlertRule: string
{
    use HasValues;

    case SchedulerStopped = 'scheduler_stopped';
    case ScheduledTask = 'scheduled_task';
    case QueueWait = 'queue_wait';
    case WebhookFailures = 'webhook_failures';
    case BackupFailed = 'backup_failed';
    case BackupDrill = 'backup_drill';
    case MessagingProvider = 'messaging_provider';
    case ErrorSpike = 'error_spike';

    public function label(): string
    {
        return match ($this) {
            self::SchedulerStopped => 'Scheduler stopped',
            self::ScheduledTask => 'Scheduled task failed or missed',
            self::QueueWait => 'Queue waiting too long',
            self::WebhookFailures => 'Paystack webhook failures',
            self::BackupFailed => 'Backup failed',
            self::BackupDrill => 'No restore drill this quarter',
            self::MessagingProvider => 'Messaging provider failing',
            self::ErrorSpike => 'Error spike',
        };
    }

    /**
     * A reminder is sent once and never repeated while it stays open; the
     * other rules remind every `operations.alert_renotify_minutes`.
     */
    public function repeats(): bool
    {
        return $this !== self::BackupDrill;
    }
}
