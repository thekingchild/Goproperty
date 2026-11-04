<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Operations\Alerts\AlertMonitor;
use App\Platform\Operations\Alerts\Checks\BackupDrillCheck;
use App\Platform\Operations\Alerts\Checks\BackupFailedCheck;
use App\Platform\Operations\Alerts\Checks\ErrorSpikeCheck;
use App\Platform\Operations\Alerts\Checks\MessagingProviderCheck;
use App\Platform\Operations\Alerts\Checks\QueueWaitCheck;
use App\Platform\Operations\Alerts\Checks\ScheduledTaskCheck;
use App\Platform\Operations\Alerts\Checks\SchedulerStoppedCheck;
use App\Platform\Operations\Alerts\Checks\WebhookFailureCheck;
use App\Platform\Operations\Alerts\Notices\AlertNotifier;
use App\Platform\Operations\Health\ErrorCounter;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Operations\Scheduler\ScheduledTasks;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The System Operations dashboard's background parts (P08-03,
 * PLATFORM_CONSOLE.md § 2): the alert checks, each scheduled task's last
 * result, and the count of reported exceptions behind the error figures.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    /** In the order the dashboard lists their alerts. */
    public const array CHECKS = [
        SchedulerStoppedCheck::class,
        ScheduledTaskCheck::class,
        QueueWaitCheck::class,
        WebhookFailureCheck::class,
        BackupFailedCheck::class,
        BackupDrillCheck::class,
        MessagingProviderCheck::class,
        ErrorSpikeCheck::class,
    ];

    public function register(): void
    {
        $this->app->bind(AlertMonitor::class, static fn (Application $app): AlertMonitor => new AlertMonitor(
            array_map(static fn (string $check) => $app->make($check), self::CHECKS),
            $app->make(AlertNotifier::class),
            $app->make(OperationsAudit::class),
            $app->make(Cache::class),
        ));
    }

    public function boot(): void
    {
        // Last run and result of every scheduled task (PLATFORM_CONSOLE.md § 2 "Scheduler").
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => $this->app->make(ScheduledTasks::class)->record($event->task, 'ok', seconds: $event->runtime));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => $this->app->make(ScheduledTasks::class)->record($event->task, 'failed', $event->exception));
        Event::listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => $this->app->make(ScheduledTasks::class)->record($event->task, 'skipped'));

        // Every reported exception counts towards the error figures and the spike alert.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(ErrorCounter::class)->record();
                });
            }
        });
    }
}
