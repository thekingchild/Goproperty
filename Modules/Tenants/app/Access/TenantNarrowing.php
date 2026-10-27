<?php

declare(strict_types=1);

namespace Modules\Tenants\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Models\TenantProfile;

/**
 * Which tenants a member sees, for agents narrowed to some buildings
 * (ARCHITECTURE.md § 5 "Agent assignment and narrowing"; orchestrator
 * decision 9: tenant pages narrow through
 * Modules\Properties\Contracts\BuildingAccess). Used by TenantProfilePolicy
 * (view, so another tenant answers 404), by Lists\TenantList and by
 * Contracts\TenantDirectory's picker.
 *
 * A tenant is tied to a building only through a lease (P03-03a), read
 * through the Leasing module's Contracts\TenantLeases: a narrowed agent
 * sees the tenants with no lease at all (they add the tenants of the
 * leases they sign) and the tenants with a lease, of any status, in one of
 * their buildings; a tenant whose leases are all in other buildings answers
 * 404. Everyone else sees every tenant. Without the Leasing module no
 * tenant has a building, so a narrowed agent sees them all.
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
     * Limits a tenants query to the tenants the member sees, as allows()
     * answers: for a narrowed agent, tenants without a lease or with a lease
     * in one of their buildings.
     *
     * @param  Builder<TenantProfile>  $query
     * @return Builder<TenantProfile>
     */
    public function tenants(Builder $query, User $user): Builder
    {
        $limited = $this->access->limitedTo($user);
        $leases = self::leases();

        if ($limited === null || $leases === null) {
            return $query;
        }

        $id = $query->getModel()->qualifyColumn('id');

        return $query->where(fn (Builder $query) => $query
            ->whereNotIn($id, $leases->tenantIdsQuery())
            ->orWhereIn($id, $leases->tenantIdsQuery($limited)));
    }

    /**
     * The buildings the tenant's leases are in.
     *
     * @return list<int>
     */
    private function buildingsOf(TenantProfile $tenant): array
    {
        return self::leases()?->buildingsOf($tenant->id) ?? [];
    }

    /** The Leasing module's answers, when it is on. */
    private static function leases(): ?TenantLeases
    {
        return app()->bound(TenantLeases::class) ? app(TenantLeases::class) : null;
    }
}
