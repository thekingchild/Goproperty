<?php

declare(strict_types=1);

namespace Modules\Leasing\Events;

/**
 * A change in a lease's life (ARCHITECTURE.md § 6.2–6.3), for other
 * modules: plain ids and values only, never the Leasing models.
 *
 * Sent by LeaseService inside the transaction that made the change, so a
 * listener that writes (the Properties module keeps `units.status` in step
 * through `unitStatus`) commits or rolls back with it. A listener that
 * should only act once the change is saved (mail, a queued PDF) implements
 * Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit.
 *
 * `unitStatus` is the status the unit must have now (a
 * Modules\Properties\Contracts\UnitDirectory status: occupied while a lease
 * is active, reserved while only drafts wait for it, vacant once no lease
 * holds it), or null when it stays as it is. Leasing decides it with the
 * unit's row locked (UnitDirectory::lock), from every lease of the unit.
 */
abstract readonly class LeaseEvent
{
    public function __construct(
        public int $organizationId,
        public int $leaseId,
        public int $propertyId,
        public int $unitId,
        public int $tenantProfileId,
        public ?string $unitStatus,
    ) {}
}
