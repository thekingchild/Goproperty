<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A lease with rent to bill, as Billing sees it (LeaseBillingSchedule):
 * plain values. Periods count from `startDate` (the anchor) in `cycle`
 * (CycleCalculator); `nextBillingDate` is the start of the next period to
 * bill; no period starting after `endDate` is billed. `rent` is the amount
 * of one period. `dueDayOffset` is days after a period start the bill is
 * due (CycleCalculator::dueDate()).
 */
final readonly class BillableLease
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $propertyId,
        public int $unitId,
        public int $tenantProfileId,
        public string $contractNumber,
        public string $status,
        public BillingCycle $cycle,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public CarbonImmutable $nextBillingDate,
        public Money $rent,
        public int $dueDayOffset,
    ) {}
}
