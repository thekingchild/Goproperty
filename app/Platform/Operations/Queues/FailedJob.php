<?php

declare(strict_types=1);

namespace App\Platform\Operations\Queues;

use Carbon\CarbonImmutable;

/** A failed job as the console lists it: never its payload, only what it was and why it failed (numbers masked). */
final readonly class FailedJob
{
    public function __construct(
        public string $uuid,
        public string $queue,
        public string $job,
        public ?CarbonImmutable $failedAt,
        public ?string $error,
    ) {}
}
