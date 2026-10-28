<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something that decides whether moved tenants' records may archive
 * themselves changed (BILLING_PAYMENTS.md § 7.4): they moved out with their
 * buildings, or the credit they held at a handover was settled. Plain ids;
 * sent by the Handovers module once its transaction committed. The Tenants
 * module archives each record that is now clear (no live lease, no unpaid
 * bill, no credit); the daily sweep catches whatever this misses.
 */
final class MovedTenantsChanged
{
    use Dispatchable;

    /** @param  list<int>  $tenantProfileIds  in $organizationId */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $tenantProfileIds,
    ) {}
}
