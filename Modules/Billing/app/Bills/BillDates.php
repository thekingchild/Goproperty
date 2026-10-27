<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Carbon\CarbonImmutable;

/**
 * Bill dates are calendar days in Lagos (as lease dates and document
 * numbers): "today" is the date in Africa/Lagos, at midnight in the app's
 * time zone, so it compares with the `date` columns read back from the
 * database. Period and due-date maths go through
 * Modules\Leasing\Contracts\CycleCalculator (AGENT.md § 3 rule 3).
 */
final class BillDates
{
    public const string TIMEZONE = 'Africa/Lagos';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString());
    }
}
