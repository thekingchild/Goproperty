<?php

declare(strict_types=1);

namespace App\Platform\Operations\Queues;

use Carbon\CarbonImmutable;

/** One queue right now: jobs waiting (due), delayed (due later), running, and how long the oldest due job has waited. */
final readonly class QueueFigures
{
    public function __construct(
        public string $queue,
        public ?int $waiting,
        public ?int $delayed,
        public ?int $running,
        public ?CarbonImmutable $oldestWaitingSince,
    ) {}

    public function waitSeconds(CarbonImmutable $now): ?int
    {
        return $this->oldestWaitingSince === null ? null : max(0, (int) $this->oldestWaitingSince->diffInSeconds($now, true));
    }
}
