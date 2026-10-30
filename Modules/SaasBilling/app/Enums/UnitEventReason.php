<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Why a metering ledger row differs from the one before
 * (`subscription_unit_events.reason`, P06-02; DATABASE_SCHEMA.md § 2).
 */
enum UnitEventReason: string
{
    use HasValues;

    /** The subscription's first count. */
    case Initial = 'initial';

    /** Another plan or plan version than at the last count. */
    case PlanChange = 'plan_change';

    case UnitAdded = 'unit_added';
    case UnitRemoved = 'unit_removed';
    case Unchanged = 'unchanged';
}
