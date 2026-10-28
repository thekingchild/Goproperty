<?php

declare(strict_types=1);

namespace Modules\Expenses\Recording;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the expense pages type and show: amounts in naira ("150,000",
 * "₦1,500,000.50") as App\Support\Money (kobo, never a float), and dates
 * as Y-m-d in Lagos.
 */
final class ExpenseInput
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** ₦10bn: far above any single expense, well inside the kobo column. */
    public const int MAX_NAIRA = 10_000_000_000;

    /** The amount typed, or null when it is not an amount of naira (negative amounts included). */
    public static function amount(string $typed): ?Money
    {
        $typed = str_replace([' ', "\u{00A0}"], '', trim($typed));

        try {
            $amount = Money::naira($typed);
        } catch (Throwable) {
            return null;
        }

        return $amount->isNegative() ? null : $amount;
    }

    /** What a form shows for an amount: "1,500,000" or "1,500,000.50". */
    public static function showAmount(Money $amount): string
    {
        return str_replace($amount->currency()->symbol(), '', $amount->format());
    }

    /** Today in Lagos, as a date. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::TIMEZONE)->toDateString());
    }

    /** A Y-m-d string as a date (midnight, app time zone), or null when it is not one. */
    public static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
