<?php

declare(strict_types=1);

namespace App\Documents\Parts;

use Carbon\CarbonInterface;

/**
 * How documents print dates and times (BRANDING_DOCUMENTS.md § 2–3, as in
 * the design templates): days as 31/12/2026, times in Lagos (WAT), whatever
 * the server's timezone.
 */
final class DocumentDates
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** "31/12/2026" */
    public static function day(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->format('d/m/Y');
    }

    /** "31 Dec 2026" (the amount-due panel). */
    public static function long(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->format('j M Y');
    }

    /** "14/12/2026, 10:42 WAT" */
    public static function time(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone(self::TIMEZONE)->format('d/m/Y, H:i').' WAT';
    }
}
