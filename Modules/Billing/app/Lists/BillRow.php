<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use Modules\Billing\Models\Bill;

/**
 * A bill with the names the list shows next to it: the tenant's, the
 * unit's label and its building's (read through the Tenants and
 * Properties contracts, a page or export chunk at a time: BillParties).
 */
final readonly class BillRow
{
    public function __construct(
        public Bill $bill,
        public string $tenantName,
        public ?string $unitLabel,
        public ?string $buildingName,
    ) {}
}
