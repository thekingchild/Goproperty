<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Collections\Enums\DateOrder;

/**
 * Reads a transaction date as bank exports write it: Excel date cells (or
 * their serial numbers), 31/01/2026, 31-01-2026, 31.01.26, 31-Jan-2026,
 * 31 January 2026, 31JAN26, Jan 31, 2026, 2026-01-31 and 20260131, with
 * any time of day after it ignored. Numbers-only dates follow the
 * statement's DateOrder (day first unless the mapping says otherwise); a
 * four-digit year first is always year-month-day. The calendar date is
 * kept as written (no time zone shift).
 */
final class StatementDate
{
    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** Excel's day 0 for serial dates (the 1900 date system, after its 1900 leap-year bug). */
    private const string EXCEL_EPOCH = '1899-12-30';

    /** @throws UnreadableRow */
    public static function read(mixed $cell, DateOrder $order): CarbonImmutable
    {
        $date = match (true) {
            $cell instanceof DateTimeInterface => self::make((int) $cell->format('Y'), (int) $cell->format('n'), (int) $cell->format('j')),
            is_int($cell) => self::serial($cell),
            is_string($cell) => self::text(trim(str_replace("\u{00A0}", ' ', $cell)), $order),
            default => null,
        };

        if ($date === null) {
            $shown = is_scalar($cell) ? mb_substr(trim((string) $cell), 0, 40) : '';

            throw new UnreadableRow($shown === '' ? __('The row has no date.') : __('":value" is not a date.', ['value' => $shown]));
        }

        return $date;
    }

    private static function text(string $text, DateOrder $order): ?CarbonImmutable
    {
        if ($text === '') {
            return null;
        }

        // 2026-01-31, 2026/01/31, 2026.01.31 (a time after it is ignored).
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:$|[\sT])/', $text, $m) === 1) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 20260131
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $m) === 1) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 31/01/2026, 01/31/2026, 31-01-26, 31.01.2026 (in the statement's order).
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:$|\s)/', $text, $m) === 1) {
            [$a, $b, $c] = [(int) $m[1], (int) $m[2], self::year($m[3])];

            return match ($order) {
                DateOrder::DayFirst => self::make($c, $b, $a),
                DateOrder::MonthFirst => self::make($c, $a, $b),
                DateOrder::YearFirst => strlen($m[1]) === 2 && strlen($m[3]) <= 2 ? self::make(2000 + $a, $b, (int) $m[3]) : null,
            };
        }

        // 31-Jan-2026, 31 January 2026, 31JAN26, 31-Jan-26 10:22
        if (preg_match('/^(\d{1,2})[-\/. ]?([a-z]{3,9})\.?[-\/., ]*(\d{2}|\d{4})(?:$|[\s,])/i', $text, $m) === 1) {
            $month = self::month($m[2]);

            return $month === null ? null : self::make(self::year($m[3]), $month, (int) $m[1]);
        }

        // Jan 31, 2026 / January 31 2026
        if (preg_match('/^([a-z]{3,9})\.?[ -]+(\d{1,2})(?:st|nd|rd|th)?,?[ -]+(\d{4})(?:$|\s)/i', $text, $m) === 1) {
            $month = self::month($m[1]);

            return $month === null ? null : self::make((int) $m[3], $month, (int) $m[2]);
        }

        // A serial number saved as text.
        if (preg_match('/^\d{5}(?:\.\d+)?$/', $text) === 1) {
            return self::serial((int) $text);
        }

        return null;
    }

    private static function serial(int $days): ?CarbonImmutable
    {
        // 1954 to 2119: anything else in a date column is not a date.
        if ($days < 20000 || $days > 80000) {
            return null;
        }

        return CarbonImmutable::parse(self::EXCEL_EPOCH, 'UTC')->addDays($days)->startOfDay();
    }

    private static function month(string $name): ?int
    {
        $name = strtolower($name);
        $full = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];

        return self::MONTHS[$name] ?? $full[$name] ?? null;
    }

    private static function year(string $digits): int
    {
        return strlen($digits) === 2 ? 2000 + (int) $digits : (int) $digits;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($year < 1900 || $year > 2999 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
