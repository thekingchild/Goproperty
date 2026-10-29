<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Billing\Contracts\OwnerBill;
use Modules\Payments\Contracts\OwnerPayment;

/**
 * A bill of one of the owner's buildings (P03-15, P03-16) as an on-screen
 * page, never the issued invoice (it prints the organization's bank
 * account and the payment reference): the building it belongs to, its
 * lease when the owner may open it, and what was paid on it.
 */
final readonly class OwnerBillPage
{
    /** @param  list<OwnerPayment>  $payments  newest first */
    public function __construct(
        public OwnerAgency $agency,
        public OwnerBill $bill,
        public int $buildingId,
        public string $building,
        public ?int $leaseId,
        public array $payments = [],
        public ?int $tenantId = null,
    ) {}
}
