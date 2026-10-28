<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;

/**
 * The dates an imported lease gets (DATA_IMPORT.md § 3), worked out by the
 * calculator only (AGENT.md § 3 rule 3), for the dry run's warnings and
 * LeaseService::importOngoing() alike. "Today" is the Lagos date of the
 * dry run or of the commit.
 *
 * - Billing starts with the period after "paid until" (the lease start
 *   when nothing is paid), but never with a period that started before
 *   today: past periods are never billed (no back-billing; arrears go on
 *   the Opening balances sheet), so no bill is ever already past due when
 *   an import makes it, and nothing is ever sent about a past date.
 * - A month-to-month lease (ended, the tenant stays on monthly) runs as an
 *   active monthly lease for twelve monthly periods from the one running
 *   now (or the one paid up to, when later), auto-renewing.
 */
final readonly class OngoingLeaseDates
{
    public const int MONTH_TO_MONTH_PERIODS = 12;

    public function __construct(private CycleCalculator $calculator) {}

    /** The end date the lease is imported with. */
    public function endDate(ImportedLeaseStatus $status, CarbonImmutable $start, CarbonImmutable $end, ?CarbonImmutable $paidUntil, CarbonImmutable $today): CarbonImmutable
    {
        if ($status !== ImportedLeaseStatus::MonthToMonth) {
            return $end;
        }

        $from = $paidUntil !== null && $paidUntil->greaterThan($today) ? $paidUntil : $today;
        $current = $this->calculator->periodContaining($start, BillingCycle::Monthly, $from);

        return $this->calculator->period($start, BillingCycle::Monthly, $current->index + self::MONTH_TO_MONTH_PERIODS - 1)->end;
    }

    /** The first period start the import leaves to bill (`leases.next_billing_date`). */
    public function firstBillingDate(BillingCycle $cycle, CarbonImmutable $start, ?CarbonImmutable $paidUntil, CarbonImmutable $today): CarbonImmutable
    {
        $afterPaid = $this->afterPaid($cycle, $start, $paidUntil);
        $firstNew = $this->firstStartingFrom($cycle, $start, $today);

        return $afterPaid->greaterThan($firstNew) ? $afterPaid : $firstNew;
    }

    /**
     * The past days "paid until" leaves unpaid that GoProperty will not
     * bill ([first day, last day]), or null when there are none.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function unbilled(BillingCycle $cycle, CarbonImmutable $start, ?CarbonImmutable $paidUntil, CarbonImmutable $today): ?array
    {
        $afterPaid = $this->afterPaid($cycle, $start, $paidUntil);
        $firstNew = $this->firstStartingFrom($cycle, $start, $today);

        return $afterPaid->lessThan($firstNew) ? [$afterPaid, $this->calculator->daysAfter($firstNew, -1)] : null;
    }

    private function afterPaid(BillingCycle $cycle, CarbonImmutable $start, ?CarbonImmutable $paidUntil): CarbonImmutable
    {
        return $paidUntil === null || $paidUntil->lessThan($start) ? $start : $this->calculator->nextPeriodStart($start, $cycle, $paidUntil);
    }

    /** The first period start on or after today. */
    private function firstStartingFrom(BillingCycle $cycle, CarbonImmutable $start, CarbonImmutable $today): CarbonImmutable
    {
        return $start->greaterThanOrEqualTo($today) ? $start : $this->calculator->nextPeriodStart($start, $cycle, $this->calculator->daysAfter($today, -1));
    }
}
