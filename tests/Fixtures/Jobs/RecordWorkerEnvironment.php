<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** Records what the process that ran it could do, for the cron drill. */
final class RecordWorkerEnvironment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::forever('drill:'.$this->token, [
            'pid' => getmypid(),
            'exec' => function_exists('exec'),
            'proc_open' => function_exists('proc_open'),
            'pcntl_signal' => function_exists('pcntl_signal'),
        ]);
    }
}
