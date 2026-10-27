<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a statement writes dates in numbers only. Nigerian banks write the
 * day first (31/01/2026), so that is the default; a file exported with
 * another order is mapped once and remembered for the account. Dates with
 * a month name (31-Jan-2026) or a four-digit year first (2026-01-31), and
 * Excel date cells, read the same whatever the order.
 */
enum DateOrder: string
{
    use HasValues;

    case DayFirst = 'dmy';
    case MonthFirst = 'mdy';
    case YearFirst = 'ymd';

    public function label(): string
    {
        return match ($this) {
            self::DayFirst => 'Day first (31/01/2026)',
            self::MonthFirst => 'Month first (01/31/2026)',
            self::YearFirst => 'Year first (2026/01/31)',
        };
    }
}
