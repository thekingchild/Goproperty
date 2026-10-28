<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Billing\Contracts\OwnerBill;

/** A lease of one of the owner's units with its bills (P03-15). */
final readonly class OwnerLeasePage
{
    /** @param  list<OwnerBill>  $bills  latest due date first */
    public function __construct(
        public OwnerAgency $agency,
        public OwnerLease $lease,
        public array $bills,
        public int $billCount,
    ) {}
}
