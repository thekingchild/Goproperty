<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * What the Tenants module may know about a tenant's leases (ARCHITECTURE.md
 * § 8 rule 3): which buildings they lease in (agent narrowing, P03-01a's
 * TenantNarrowing), whether a lease still holds a unit for them (archiving
 * a tenant is refused then), and their leases for the tenant's page.
 * Leases removed by an import rollback (soft deleted) do not count.
 * Answers for the current organization only; no permission check (the
 * caller checks its own policy). Bound in LeasingServiceProvider.
 */
interface TenantLeases
{
    /**
     * The buildings of the tenant's leases, whatever their status (a past
     * lease still ties a tenant to the building).
     *
     * @return list<int>
     */
    public function buildingsOf(int $tenantProfileId): array;

    /**
     * A sub-query selecting `tenant_profile_id` of the organization's
     * leases (any status), only those in these buildings when given; for
     * `whereIn('id', …)` on a tenants query.
     *
     * @param  list<int>|null  $propertyIds
     */
    public function tenantIdsQuery(?array $propertyIds = null): Builder;

    /** Whether a draft or active lease holds a unit for the tenant. */
    public function holdsUnit(int $tenantProfileId): bool;

    /**
     * The tenant's leases, newest start first.
     *
     * @return list<LeaseSummary>
     */
    public function forTenant(int $tenantProfileId): array;
}
