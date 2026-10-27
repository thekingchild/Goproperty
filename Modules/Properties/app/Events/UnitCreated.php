<?php

declare(strict_types=1);

namespace Modules\Properties\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit now counts towards the organization's GoProperty bill
 * (BILLING_PAYMENTS.md § 1.4): it was added, or an archived unit was
 * brought back (`restored`). For P06 metering (`subscription_unit_events`,
 * reason `unit_added`). Sent once per unit, after the transaction that
 * added it commits, so a rolled-back bulk add sends nothing. Plain values
 * only: listeners in other modules never see the Properties models.
 */
final readonly class UnitCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $propertyId,
        public int $unitId,
        public bool $restored = false,
    ) {}
}
