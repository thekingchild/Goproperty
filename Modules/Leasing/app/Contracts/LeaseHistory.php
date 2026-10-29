<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Support\Money;

/**
 * A lease of one of the owner's units with its history (OwnerLeases,
 * P03-16): the lease, the lease it renews and the activated renewal that
 * followed it (ids, when this organization holds them), the timeline of
 * moves (`events`, oldest first), the deposit agreed and its ledger
 * (`depositLines`, oldest first, with the balance held now) and whether an
 * issued contract is on file here (`hasContract`: the owner may download
 * it, OwnerLeaseContracts).
 */
final readonly class LeaseHistory
{
    /**
     * @param  list<LeaseEvent>  $events
     * @param  list<DepositLine>  $depositLines
     */
    public function __construct(
        public LeaseSummary $lease,
        public ?int $renewalOfLeaseId,
        public ?int $renewedByLeaseId,
        public array $events,
        public Money $deposit,
        public array $depositLines,
        public Money $depositBalance,
        public bool $hasContract,
    ) {}
}
