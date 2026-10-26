<?php

declare(strict_types=1);

namespace App\Providers;

use App\Hosting\Queue\TickWorker;
use App\Hosting\Realtime;
use App\Pdf\PdfManager;
use App\Pdf\PdfRenderer;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Profile-dependent services: queue tick worker, realtime and PDF drivers (ARCHITECTURE.md § 12.1). */
final class HostingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TickWorker::class, function (Application $app): TickWorker {
            $worker = new TickWorker(
                $app->make('queue'),
                $app->make('events'),
                $app->make(ExceptionHandler::class),
                fn (): bool => $app->isDownForMaintenance(),
                // Same per-job reset the queue:work daemon does between jobs.
                function () use ($app): void {
                    $app->forgetScopedInstances();
                },
            );
            $worker->setName('queue-tick');
            $worker->setCache($app->make('cache')->driver());
            $worker->setFailedJobProvider($app->make('queue.failer'));
            $app->make('events')->listen(JobFailed::class, $worker->logFailedJob(...));

            return $worker;
        });

        $this->app->singleton(Realtime::class, fn (Application $app): Realtime => Realtime::fromConfig($app->make('config')));

        $this->app->singleton(PdfManager::class);
        $this->app->bind(PdfRenderer::class, fn (Application $app): PdfRenderer => $app->make(PdfManager::class)->renderer());
    }

    public function boot(): void
    {
        // Laravel's scheduler calls pcntl_async_signals() before every event
        // whenever the pcntl extension is loaded. Shared hosts often load it
        // but list its functions in disable_functions, and then every
        // scheduled event fails. The signal hook only releases
        // withoutOverlapping() mutexes, so skip it when it cannot work.
        if (self::signalsUsable()) {
            return;
        }

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            $event->task->releaseOnTerminationSignals = false;
        });
    }

    public static function signalsUsable(): bool
    {
        return ! extension_loaded('pcntl')
            || (function_exists('pcntl_async_signals') && function_exists('pcntl_signal'));
    }
}
