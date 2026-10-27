<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use Carbon\CarbonImmutable;

/**
 * Payment dates are calendar days in Lagos (the day the money arrived), as
 * lease and bill dates are: "today" is the date in Africa/Lagos at midnight
 * in the app's time zone, so it compares with `date` columns.
 */
final class PaymentDates
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
