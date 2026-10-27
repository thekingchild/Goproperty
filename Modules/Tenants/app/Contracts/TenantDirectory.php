<?php

declare(strict_types=1);

namespace Modules\Tenants\Contracts;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * How other modules read tenants (ARCHITECTURE.md § 8 rule 3), Leasing
 * first (P03-03a): never the Tenants module's models or tables. Answers for
 * the current organization only (OrganizationContext; without one it
 * throws App\Tenancy\MissingOrganizationContext), so another
 * organization's tenant is simply not found.
 *
 * With $for, only the tenants that member may see (TenantProfilePolicy,
 * which narrows agents assigned to buildings); without it, no permission
 * check: the caller checks its own policy. Bound in TenantsServiceProvider.
 */
interface TenantDirectory
{
    /** The tenant (archived ones too), or null when there is no such tenant here, or $for may not see them. */
    public function find(int $tenantId, ?User $for = null): ?TenantSummary;

    /**
     * The tenants of these ids (archived ones too) that the current
     * organization has; unknown ids are left out.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, TenantSummary> by id
     */
    public function findMany(array $tenantIds): array;

    /**
     * Ids of the tenants (archived ones too) whose name, phone number or
     * email matches the search, at most $limit of them.
     *
     * @return list<int>
     */
    public function search(string $search, int $limit = 1000): array;

    /**
     * Tenants who are not archived, for a picker shown to the member: id =>
     * "Name (phone)", by name, at most $limit.
     *
     * @return array<int, string>
     */
    public function activeOptions(User $for, int $limit = 1000): array;

    /**
     * Lock the tenant's row (SELECT … FOR UPDATE) until the caller's
     * transaction ends and return them, or null when there is no such
     * tenant here or they are archived. A lease drafted for a tenant and the
     * tenant's archiving wait for each other here (P03-03a).
     *
     * @throws LogicException outside a database transaction
     */
    public function lockActive(int $tenantId): ?TenantSummary;

    /**
     * Whether the member's building narrowing (an agent assigned to
     * buildings, Access\TenantNarrowing) lets them see the tenant, archived
     * ones too. No permission check: for modules whose records follow a
     * tenant (payments, P03-10a) and check their own permission. False when
     * there is no such tenant here.
     */
    public function narrowingAllows(User $member, int $tenantId): bool;

    /**
     * A sub-query selecting the ids of the tenants (archived ones too) the
     * member's building narrowing lets them see, for `whereIn(…)` on another
     * module's tenant column; null when the member sees every tenant.
     */
    public function narrowedIdsQuery(User $member): ?Builder;
}
