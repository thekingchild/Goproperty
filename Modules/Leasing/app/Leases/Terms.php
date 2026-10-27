<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\BillingCycle;

/** A lease's terms, checked by LeaseTerms. `rent` is one period's rent. */
final readonly class Terms
{
    public function __construct(
        public BillingCycle $cycle,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public Money $rent,
        public Money $deposit,
        public int $dueDayOffset,
        public ?CarbonImmutable $paidUntil,
        public bool $autoRenew,
        public ?string $notes,
    ) {}
}
