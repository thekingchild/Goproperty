<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use Carbon\CarbonImmutable;

/**
 * Upcoming renewals (P08-01): active leases whose last day is between today
 * and the window's end (30, 60 or 90 days), soonest first, each with where
 * its renewal stands (a renewal drafted, rolls on by itself, or not yet),
 * as the owner statements show them (P08-05).
 */
final readonly class RenewalsReport
{
    /** @param  list<RenewalLine>  $lines */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public int $days,
        public array $lines,
    ) {}

    public function notRenewedCount(): int
    {
        return count(array_filter($this->lines, static fn (RenewalLine $line): bool => $line->renewalState === 'not_renewed'));
    }
}
