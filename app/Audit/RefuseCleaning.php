<?php

declare(strict_types=1);

namespace App\Audit;

use RuntimeException;
use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * activitylog:clean is refused: the audit log is append-only and kept 7
 * years for money entries (PLATFORM_CONSOLE.md § 7), and deleting rows
 * would break the hash chain. Retention comes with the archive job.
 */
final class RefuseCleaning extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        throw new RuntimeException('The audit log is append-only; activitylog:clean is disabled (PLATFORM_CONSOLE.md § 7).');
    }
}
