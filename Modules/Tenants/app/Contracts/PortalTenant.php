<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

use App\Models\Organization;

/**
 * One organization the signed-in user rents from, as other modules' portal
 * pages see it (Contracts\TenantPortalAccess): the organization and the
 * user's tenant profile ids there (usually one).
 */
final readonly class PortalTenant
{
    /** @param  list<int>  $tenantProfileIds */
    public function __construct(
        public Organization $organization,
        public array $tenantProfileIds,
    ) {}

    /** A suspended or cancelled organization's records are read-only in the portal (ARCHITECTURE.md § 4). */
    public function readOnly(): bool
    {
        return $this->organization->status->blocksWrites();
    }
}
