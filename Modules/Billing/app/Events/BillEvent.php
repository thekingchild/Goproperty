<?php

declare(strict_types=1);

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a bill (Billing's domain events, ARCHITECTURE.md
 * § 8 rule 3): plain ids and values, never the model. Sent inside the
 * transaction that made the change; a listener that must act only once it
 * is committed implements ShouldHandleEventsAfterCommit.
 */
abstract class BillEvent
{
    use Dispatchable;

    public function __construct(
        public readonly int $billId,
        public readonly int $organizationId,
        public readonly int $tenantProfileId,
        public readonly ?int $leaseId,
        public readonly string $type,
        public readonly string $number,
        public readonly int $amountKobo,
    ) {}
}
