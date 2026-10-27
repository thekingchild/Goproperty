<?php

declare(strict_types=1);

namespace Modules\Billing\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a bill is for (`bills.billable_type` with `billable_id`,
 * DATABASE_SCHEMA.md § 4: a lease or a unit). Stored as these short keys,
 * not class names: the lease and the unit belong to other modules, which
 * Billing reaches through their contracts only (ARCHITECTURE.md § 8).
 */
enum BillableType: string
{
    use HasValues;

    case Lease = 'lease';
    case Unit = 'unit';
}
