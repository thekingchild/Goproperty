<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentImpersonation;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Support\Facades\Gate;
use Modules\Tenants\Models\TenantProfile;

/**
 * The tenant portal's "records linked to my user" path (ARCHITECTURE.md
 * § 4, P03-11a): the only way a /portal page reaches organization data.
 * A tenant rents from any number of organizations and never gets an
 * `organization_user` row (P03 decision 4), so the portal sets no
 * organization context of its own. Instead:
 *
 * 1. one unscoped read of `tenant_profiles` (`organization_id`, `id`) by
 *    `user_id` (LinkedTenantProfiles::byOrganization; archived profiles
 *    left out) — the P01-04 / P02-04 lookup pattern (orchestrator
 *    decision 2): no scope is removed and nothing else is read unscoped;
 * 2. for each of those organizations, OrganizationContext::run with the
 *    scope in place: the profiles are loaded again through the scope and
 *    kept only when TenantProfilePolicy::viewAsTenant allows them (still
 *    linked to this user, not archived);
 * 3. everything else (leases, units, bills) is read inside that same
 *    context, through the other modules' contracts, limited to those
 *    profile ids.
 *
 * Record pages find their record with first(): the record must be found
 * among the user's own profiles in one of their organizations, so a guessed
 * id of another tenant's or another organization's record gets the same
 * 404 as a missing one.
 *
 * While GoProperty staff impersonate the user, only the support session's
 * organization is opened (PLATFORM_CONSOLE.md § 6 "Scope": the portals list
 * only the session's organization); every other tenancy of the user stays
 * hidden, as their other workspaces do.
 */
final readonly class PortalTenancies
{
    public function __construct(
        private LinkedTenantProfiles $linked,
        private OrganizationContext $context,
        private CurrentImpersonation $impersonation,
    ) {}

    /**
     * Every organization the user rents from (that they may open now), by name.
     *
     * @return list<PortalTenancy>
     */
    public function of(User $user): array
    {
        $profiles = array_filter(
            $this->linked->byOrganization($user),
            fn (int $organizationId): bool => $this->opens($user, $organizationId),
            ARRAY_FILTER_USE_KEY,
        );

        if ($profiles === []) {
            return [];
        }

        $tenancies = [];

        foreach (Organization::query()->whereKey(array_keys($profiles))->orderBy('name')->orderBy('id')->get() as $organization) {
            $ids = $this->context->run($organization, static fn (): array => array_values(TenantProfile::query()
                ->whereKey($profiles[$organization->id])
                ->orderBy('id')
                ->get()
                ->filter(static fn (TenantProfile $profile): bool => Gate::forUser($user)->allows('viewAsTenant', $profile))
                ->map(static fn (TenantProfile $profile): int => $profile->id)
                ->all()));

            if ($ids !== []) {
                $tenancies[] = new PortalTenancy($organization, $ids);
            }
        }

        return $tenancies;
    }

    /**
     * $callback for each tenancy, run inside its organization.
     *
     * @template T
     *
     * @param  Closure(PortalTenancy): T  $callback
     * @return list<T>
     */
    public function map(User $user, Closure $callback): array
    {
        return array_map(
            fn (PortalTenancy $tenancy): mixed => $this->context->run($tenancy->organization, static fn (): mixed => $callback($tenancy)),
            $this->of($user),
        );
    }

    /**
     * The first non-null answer of $callback, run inside each tenancy's
     * organization in turn, with the tenancy it came from; null when no
     * tenancy has it (the page answers 404).
     *
     * @template T
     *
     * @param  Closure(PortalTenancy): (T|null)  $callback
     * @return array{PortalTenancy, T}|null
     */
    public function first(User $user, Closure $callback): ?array
    {
        foreach ($this->of($user) as $tenancy) {
            $found = $this->context->run($tenancy->organization, static fn (): mixed => $callback($tenancy));

            if ($found !== null) {
                return [$tenancy, $found];
            }
        }

        return null;
    }

    /** Whether the portal may show this organization's records to the user now (only the session's organization while staff impersonate them). */
    public function opens(User $user, int $organizationId): bool
    {
        if (! $this->impersonation->isImpersonated($user)) {
            return true;
        }

        $session = $this->impersonation->session();

        return $session !== null && (int) $session->organization_id === $organizationId;
    }
}
