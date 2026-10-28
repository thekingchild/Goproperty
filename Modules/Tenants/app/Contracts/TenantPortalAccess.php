<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

use App\Models\User;
use App\Tenancy\MissingOrganizationContext;

/**
 * The tenant portal's "records linked to my user" path (ARCHITECTURE.md
 * § 4, P03-11a) for other modules' /portal pages (maintenance tickets,
 * P04-01): which organizations the user rents from and their tenant
 * profiles there, each allowed by TenantProfilePolicy::viewAsTenant inside
 * its organization (linked to the user, not archived; while GoProperty
 * staff impersonate, only the support session's organization). Never the
 * `tenant_profiles` table itself. Bound in TenantsServiceProvider.
 */
interface TenantPortalAccess
{
    /**
     * Every organization the user rents from that the portal may open now,
     * by organization name.
     *
     * @return list<PortalTenant>
     */
    public function tenancies(User $user): array;

    /**
     * The user's tenant profiles in the current organization that the
     * portal may open now (viewAsTenant), in id order; empty when the user
     * rents nothing here. A policy asks this for "is this record the
     * user's own".
     *
     * @return list<int>
     *
     * @throws MissingOrganizationContext without an organization context
     */
    public function profileIdsHere(User $user): array;
}
