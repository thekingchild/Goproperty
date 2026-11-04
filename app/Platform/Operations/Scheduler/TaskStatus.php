<?php

declare(strict_types=1);

namespace App\Platform\Operations\Scheduler;

use Carbon\CarbonImmutable;

/** One scheduled task as the console shows it: when it last ran, how, and whether a run was missed. */
final readonly class TaskStatus
{
    public function __construct(
        public string $name,
        public string $expression,
        public ?CarbonImmutable $lastRunAt,
        /** ok|failed|skipped, null when not seen yet */
        public ?string $lastResult,
        public ?string $lastError,
        public ?CarbonImmutable $nextRunAt,
        /** The run it missed, when it did. */
        public ?CarbonImmutable $missedRunAt,
    ) {}

    public function failed(): bool
    {
        return $this->lastResult === 'failed';
    }

    public function resultLabel(): string
    {
        return match ($this->lastResult) {
            'ok' => 'OK',
            'failed' => 'Failed'.($this->lastError !== null ? " ({$this->lastError})" : ''),
            'skipped' => 'Skipped (condition or still running)',
            default => 'Not run since the list was taken',
        };
    }
}
