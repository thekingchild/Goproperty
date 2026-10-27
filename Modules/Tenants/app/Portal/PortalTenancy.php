<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\Organization;

/**
 * One organization the signed-in user rents from, as the tenant portal
 * sees it (Portal\PortalTenancies): the organization and the user's
 * tenant profiles there, each loaded inside the organization and allowed
 * by TenantProfilePolicy::viewAsTenant. Usually one profile; two when an
 * agency keeps two records for the same person.
 */
final readonly class PortalTenancy
{
    /** @param  list<int>  $tenantProfileIds */
    public function __construct(
        public Organization $organization,
        public array $tenantProfileIds,
    ) {}

    /** Members of a suspended or cancelled organization only read; the portal shows its records read-only (ARCHITECTURE.md § 4). */
    public function readOnly(): bool
    {
        return $this->organization->status->blocksWrites();
    }
}
