<?php

declare(strict_types=1);

namespace Modules\Properties\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit was archived (soft-deleted) and no longer counts towards the
 * organization's GoProperty bill (BILLING_PAYMENTS.md § 1.4). For P06
 * metering (`subscription_unit_events`, reason `unit_removed`). Sent after
 * the transaction commits. The unit row stays (AGENT.md § 3 rule 7).
 */
final readonly class UnitDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $organizationId,
        public int $propertyId,
        public int $unitId,
    ) {}
}
