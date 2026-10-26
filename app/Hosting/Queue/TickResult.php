<?php

declare(strict_types=1);

namespace App\Hosting\Queue;

final readonly class TickResult
{
    /** @param  array<string, int>  $processed  jobs run per lane */
    public function __construct(
        public TickStopReason $stoppedBy,
        public array $processed = [],
        public int $durationMs = 0,
    ) {}

    public function total(): int
    {
        return array_sum($this->processed);
    }

    /** @return array{stopped_by: string, processed: array<string, int>, total: int, duration_ms: int} */
    public function toArray(): array
    {
        return [
            'stopped_by' => $this->stoppedBy->value,
            'processed' => $this->processed,
            'total' => $this->total(),
            'duration_ms' => $this->durationMs,
        ];
    }
}
