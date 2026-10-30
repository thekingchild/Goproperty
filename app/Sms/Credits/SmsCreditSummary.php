<?php

declare(strict_types=1);

namespace App\Sms\Credits;

/**
 * An organization's SMS credits as the settings page and the console show
 * them (P05-03): the whole balance, this month's allowance and what is
 * left of it, and the purchased credits (never expire).
 */
final readonly class SmsCreditSummary
{
    public function __construct(
        /** Lagos month, "2026-12". */
        public string $month,
        public int $balance,
        /** What the plan (or a custom allowance) gives each month. */
        public int $monthlyAllowance,
        /** What this month's grant came to (0 until it is granted). */
        public int $allowanceGranted,
        public int $allowanceLeft,
        public int $purchased,
    ) {}

    /** At or under 20 % of the month's allowance (P05-03 default: when owners are told). */
    public function low(): bool
    {
        $allowance = max($this->allowanceGranted, $this->monthlyAllowance);

        return $this->balance === 0 || ($allowance > 0 && $this->balance * 100 <= $allowance * SmsCredits::LOW_PERCENT);
    }
}
