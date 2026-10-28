<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Billing\Contracts\OwnerBill;

/** One of the owner's buildings with its units, their occupancy and leases, and its latest bills (P03-15). */
final readonly class OwnerBuildingPage
{
    /**
     * @param  list<OwnerUnit>  $units
     * @param  list<OwnerBill>  $bills  latest due date first
     */
    public function __construct(
        public OwnerAgency $agency,
        public OwnerBuilding $building,
        public array $units,
        public array $bills,
        public int $billCount,
    ) {}
}
