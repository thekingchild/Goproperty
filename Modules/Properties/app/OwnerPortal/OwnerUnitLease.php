<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Leasing\Contracts\LeaseHistory;

/** A lease on the unit's timeline (OwnerUnitPage, P03-16): its history and the tenant's name. */
final readonly class OwnerUnitLease
{
    public function __construct(
        public LeaseHistory $history,
        public string $tenantName,
    ) {}

    public function statusColor(): string
    {
        return OwnerLease::colorOf($this->history->lease->status);
    }
}
