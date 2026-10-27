<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
use Throwable;

/**
 * Amounts typed on the payment pages ("150,000", "₦1,500,000.50") as
 * App\Support\Money (kobo), and back.
 */
final class NairaInput
{
    /** ₦10bn: far above any single rent payment, well inside the kobo column. */
    public const int MAX_NAIRA = 10_000_000_000;

    /** The amount typed, or null when it is not an amount of naira (negative amounts included). */
    public static function parse(string $typed): ?Money
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
    public static function show(Money $amount): string
    {
        return str_replace($amount->currency()->symbol(), '', $amount->format());
    }
}
