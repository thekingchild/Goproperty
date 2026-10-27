<?php

declare(strict_types=1);

namespace Modules\Tenants\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Models\TenantProfile;

/**
 * Which tenants a member sees, for agents narrowed to some buildings
 * (ARCHITECTURE.md § 5 "Agent assignment and narrowing"; orchestrator
 * decision 9: tenant pages narrow through
 * Modules\Properties\Contracts\BuildingAccess). Used by TenantProfilePolicy
 * (view, so another tenant answers 404) and by Lists\TenantList.
 *
 * A tenant is tied to a building only through a lease, and leases come in
 * P03-03a. Until then no tenant has a building, so the rule is: a tenant
 * with no building is seen by everyone who sees tenants, including a
 * narrowed agent (who adds the tenants of the leases they will sign); a
 * tenant whose leases are all in other buildings is not. buildingsOf() is
 * the single place P03-03a fills in, through Leasing's contract, and the
 * query limiter gains the same rule there.
 */
final readonly class TenantNarrowing
{
    public function __construct(private BuildingAccess $access) {}

    /** Whether the member's narrowing lets them see the tenant (the policy checks the rest). */
    public function allows(User $user, TenantProfile $tenant): bool
    {
        $limited = $this->access->limitedTo($user);

        if ($limited === null) {
            return true;
        }

        $buildings = $this->buildingsOf($tenant);

        return $buildings === [] || array_intersect($buildings, $limited) !== [];
    }

    /**
     * Limits a tenants query to the tenants the member sees. Every tenant
     * has no building until P03-03a, so a narrowed agent's list is the
     * organization's tenants, as allows() answers.
     *
     * @param  Builder<TenantProfile>  $query
     * @return Builder<TenantProfile>
     */
    public function tenants(Builder $query, User $user): Builder
    {
        // P03-03a: when limitedTo() is a list, keep tenants without a lease
        // or with a lease in one of those buildings.
        return $query;
    }

    /**
     * The buildings the tenant's leases are in.
     *
     * @return list<int>
     */
    private function buildingsOf(TenantProfile $tenant): array
    {
        // Leases arrive in P03-03a; no tenant has a building before them.
        return [];
    }
}
