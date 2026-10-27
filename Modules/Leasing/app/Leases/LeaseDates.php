<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Carbon\CarbonImmutable;

/**
 * Lease dates are calendar days in Lagos (ARCHITECTURE.md § 6.3: dates are
 * calendar days; the business runs on Lagos time, as document numbers do):
 * "today" is the date in Africa/Lagos, as a date at midnight in the app's
 * time zone, so it compares with the `date` columns read back from the
 * database. Day arithmetic goes through CycleCalculator (AGENT.md § 3
 * rule 3).
 */
final class LeaseDates
{
    public const string TIMEZONE = 'Africa/Lagos';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString());
    }

    /** A Y-m-d string as a date (midnight, app time zone), or null when it is not one. */
    public static function parse(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
