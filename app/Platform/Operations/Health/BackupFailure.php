<?php

declare(strict_types=1);

namespace App\Platform\Operations\Health;

use Carbon\CarbonImmutable;

/** A backup (or the monthly verify) whose latest result is a failure, not followed by a success. */
final readonly class BackupFailure
{
    public function __construct(
        /** "db:3", "files:none", "verify" */
        public string $subject,
        public string $what,
        public ?CarbonImmutable $failedAt,
    ) {}
}
