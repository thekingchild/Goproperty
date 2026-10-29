<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Billing\Contracts\OwnerBill;
use Modules\Leasing\Contracts\LeaseHistory;
use Modules\Payments\Contracts\OwnerPayment;

/**
 * A lease of one of the owner's units (P03-15, P03-16): its terms and the
 * tenant (name, phone, email), its history (moves, rent changes, the
 * deposit ledger, whether its issued contract can be downloaded), its
 * bills and what was paid on them (no references, no bank details).
 */
final readonly class OwnerLeasePage
{
    /**
     * @param  list<OwnerBill>  $bills  latest due date first
     * @param  list<OwnerPayment>  $payments  newest first, at most OwnerPortalRecords::PAYMENTS_SHOWN
     * @param  array<int, string>  $billNumbers  bill id => number, for the payments
     */
    public function __construct(
        public OwnerAgency $agency,
        public OwnerLease $lease,
        public LeaseHistory $history,
        public array $bills,
        public int $billCount,
        public array $payments,
        public int $paymentCount,
        public array $billNumbers,
    ) {}
}
