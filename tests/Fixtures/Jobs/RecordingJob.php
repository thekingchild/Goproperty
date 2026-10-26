<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** Logs its label when it runs; can take "time" or fail, for queue tick tests. */
final class RecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<string> */
    public static array $ran = [];

    public function __construct(
        public string $label,
        public int $takesSeconds = 0,
        public bool $fails = false,
    ) {}

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException("Job {$this->label} failed.");
        }

        self::$ran[] = $this->label;

        if ($this->takesSeconds > 0) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($this->takesSeconds));
        }
    }
}
