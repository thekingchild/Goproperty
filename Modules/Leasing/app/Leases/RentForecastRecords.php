<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\RentForecast;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;

/**
 * Contracts\RentForecast: the rent of the periods active and renewed leases
 * start in a window, through the CycleCalculator (AGENT.md § 3 rule 3).
 */
final readonly class RentForecastRecords implements RentForecast
{
    public function __construct(private CycleCalculator $calculator) {}

    public function expectedBetween(DateTimeInterface $from, DateTimeInterface $to): Money
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();
        $total = Money::zero();

        if ($to->lessThanOrEqualTo($from)) {
            return $total;
        }

        Lease::query()
            ->whereIn('status', [LeaseStatus::Active, LeaseStatus::Renewed])
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<', $to->toDateString())
            ->select(['id', 'organization_id', 'start_date', 'end_date', 'billing_cycle', 'rent_amount_kobo'])
            ->chunkById(500, function ($leases) use ($from, $to, &$total): void {
                foreach ($leases as $lease) {
                    /** @var Lease $lease */
                    $total = $total->plus($lease->rent_amount_kobo->times($this->periodsStarting($lease, $from, $to)));
                }
            });

        return $total;
    }

    /** How many of the lease's periods start in [$from, $to) and on or before its end date. */
    private function periodsStarting(Lease $lease, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $anchor = $lease->start_date;
        $first = $from->lessThanOrEqualTo($anchor)
            ? $anchor
            : $this->calculator->periodContaining($anchor, $lease->billing_cycle, $from)->start;

        if ($first->lessThan($from)) {
            $first = $this->calculator->nextPeriodStart($anchor, $lease->billing_cycle, $first);
        }

        $count = 0;
        $start = $first;

        while ($start->lessThan($to) && $start->lessThanOrEqualTo($lease->end_date)) {
            $count++;
            $start = $this->calculator->nextPeriodStart($anchor, $lease->billing_cycle, $start);
        }

        return $count;
    }
}
