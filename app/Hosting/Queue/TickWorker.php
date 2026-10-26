<?php

declare(strict_types=1);

namespace App\Hosting\Queue;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * Laravel's queue worker, driven one job at a time from inside the cron
 * process instead of as a daemon. It uses no `exec`/`proc_open`.
 */
final class TickWorker extends Worker
{
    private ?FailedJobProviderInterface $failer = null;

    private bool $running = false;

    public function setFailedJobProvider(FailedJobProviderInterface $failer): void
    {
        $this->failer = $failer;
    }

    /**
     * Records a failed job in `failed_jobs`. Laravel's queue:work command
     * does this, not its worker, so the tick must too. Only jobs this
     * worker is running are logged, so a VPS worker never logs twice.
     */
    public function logFailedJob(JobFailed $event): void
    {
        if (! $this->running || $this->failer === null) {
            return;
        }

        $this->failer->log(
            $event->connectionName,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception,
        );
    }

    /**
     * Pops the next job from the first non-empty queue in `$queues`
     * (comma-separated, in priority order) and runs it.
     *
     * @return bool false when every queue was empty
     */
    public function runNext(string $connectionName, string $queues, WorkerOptions $options): bool
    {
        $job = $this->getNextJob($this->manager->connection($connectionName), $queues);

        if ($job === null) {
            return false;
        }

        $enforceTimeout = $this->canEnforceTimeouts();

        if ($enforceTimeout) {
            pcntl_async_signals(true);
            $this->registerTimeoutHandler($connectionName, $queues, $job, $options);
        }

        $this->running = true;

        try {
            // runJob reports a failing job's exception and records the release
            // or failure, so one bad job never stops the tick.
            $this->runJob($job, $connectionName, $options);
        } finally {
            $this->running = false;

            if ($enforceTimeout) {
                $this->resetTimeoutHandler();
            }

            if ($this->resetScope !== null) {
                ($this->resetScope)();
            }
        }

        return true;
    }

    /**
     * Timeouts need pcntl signals. Shared hosts often load the extension but
     * list its functions in `disable_functions`, where calling them is a
     * fatal error, so check the functions rather than the extension.
     */
    private function canEnforceTimeouts(): bool
    {
        foreach (['pcntl_async_signals', 'pcntl_signal', 'pcntl_alarm'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return ! extension_loaded('posix') || function_exists('posix_kill');
    }
}
