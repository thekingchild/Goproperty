<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reading what members type into the utility forms (P03-06a): naira
 * amounts (exact, through App\Support\Money, never floats), calendar dates
 * (Y-m-d, as the date inputs send them) and one-line text.
 */
final class UtilityInput
{
    /** "25,000", "₦250.50"; null when empty, negative or not an amount. */
    public static function naira(mixed $typed): ?Money
    {
        $typed = is_string($typed) ? str_replace([' ', "\u{00A0}"], '', trim($typed)) : '';

        if ($typed === '') {
            return null;
        }

        try {
            $amount = Money::naira($typed);
        } catch (Throwable) {
            return null;
        }

        return $amount->isNegative() ? null : $amount;
    }

    /** What a form shows for an amount: "25,000" or "250.50". */
    public static function nairaInput(?Money $amount): string
    {
        return $amount === null ? '' : str_replace($amount->currency()->symbol(), '', $amount->format());
    }

    /** A Y-m-d date (a real calendar day), else null. */
    public static function date(mixed $typed): ?CarbonImmutable
    {
        if (! is_string($typed) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $typed) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $typed);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $typed ? $date : null;
    }

    /** Trimmed text with runs of spaces made one; null when empty. */
    public static function text(mixed $typed): ?string
    {
        $text = is_string($typed) ? trim((string) preg_replace('/\s+/u', ' ', $typed)) : '';

        return $text === '' ? null : $text;
    }
}
