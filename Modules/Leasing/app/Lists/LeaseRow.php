<?php

declare(strict_types=1);

namespace Modules\Leasing\Lists;

use Modules\Leasing\Models\Lease;

/**
 * A lease with the names the list shows next to it: the tenant's, the
 * unit's label and its building's (read through the Tenants and
 * Properties contracts, a page or export chunk at a time: LeaseParties).
 */
final readonly class LeaseRow
{
    public function __construct(
        public Lease $lease,
        public string $tenantName,
        public string $unitLabel,
        public string $buildingName,
        public ?string $renewalOfContract,
    ) {}
}
