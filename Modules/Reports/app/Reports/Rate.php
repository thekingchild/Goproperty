<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;

/**
 * Collected as a share of billed (P08-01), in tenths of a percent, rounded
 * toward zero, from whole kobo (no floats, AGENT.md § 3 rule 2). Collected
 * in a range can pass 100%: arrears of earlier bills are collected too.
 */
final readonly class Rate
{
    private function __construct(public int $tenths) {}

    /** The rate, or null when nothing (or less than nothing) was billed. */
    public static function of(Money $collected, Money $billed): ?self
    {
        if (! $billed->isPositive()) {
            return null;
        }

        return new self(intdiv($collected->kobo * 1000, $billed->kobo));
    }

    /** "92.5%". */
    public function label(): string
    {
        $sign = $this->tenths < 0 ? '-' : '';
        $tenths = abs($this->tenths);

        return $sign.intdiv($tenths, 10).'.'.($tenths % 10).'%';
    }

    /** The label of a rate that may be missing: "—" when nothing was billed. */
    public static function labelOf(?self $rate): string
    {
        return $rate?->label() ?? '—';
    }
}
