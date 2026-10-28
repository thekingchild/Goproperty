<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Billing\Contracts\OwnerBill;

/** A bill of one of the owner's buildings (P03-15), with the building it belongs to and its lease when the owner may open it. */
final readonly class OwnerBillPage
{
    public function __construct(
        public OwnerAgency $agency,
        public OwnerBill $bill,
        public int $buildingId,
        public string $building,
        public ?int $leaseId,
    ) {}
}
