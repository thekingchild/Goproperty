<?php

declare(strict_types=1);

namespace Modules\Reports\Enums;

use Carbon\CarbonImmutable;

/**
 * The date ranges the Reports page offers in one tap (P08-01), in Lagos
 * days; "custom" keeps the dates typed.
 */
enum ReportPreset: string
{
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case ThisYear = 'this_year';
    case Last12Months = 'last_12_months';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::ThisYear => 'This year',
            self::Last12Months => 'Last 12 months',
            self::Custom => 'Custom dates',
        };
    }

    /**
     * The first and last day of the range, for $today (a Lagos day); null
     * for custom dates.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function range(CarbonImmutable $today): ?array
    {
        $today = $today->startOfDay();

        return match ($this) {
            self::ThisMonth => [$today->startOfMonth(), $today],
            self::LastMonth => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::ThisQuarter => [$today->startOfQuarter(), $today],
            self::ThisYear => [$today->startOfYear(), $today],
            self::Last12Months => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today],
            self::Custom => null,
        };
    }

    /** The preset whose range is exactly these days (else custom). */
    public static function matching(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): self
    {
        foreach (self::cases() as $preset) {
            $range = $preset->range($today);

            if ($range !== null && $range[0]->isSameDay($from) && $range[1]->isSameDay($to)) {
                return $preset;
            }
        }

        return self::Custom;
    }
}
