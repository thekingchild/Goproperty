<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

/** A customer on the System analytics leaderboard (P08-02): a business, by name; no personal data. */
final readonly class LeaderboardEntry
{
    public function __construct(
        public int $organizationId,
        public string $name,
        public int $units,
        /** Units added (or removed, negative) over the range. */
        public int $growth,
        /** Subscription invoices due in the year to the latest day. */
        public int $invoicesDue,
        public int $paidOnTime,
    ) {}

    /** Paid on time ÷ due, in tenths of a percent; null without invoices due. */
    public function reliability(): ?int
    {
        return PlatformAnalytics::rate($this->paidOnTime, $this->invoicesDue);
    }
}
