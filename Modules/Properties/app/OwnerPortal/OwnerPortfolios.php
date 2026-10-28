<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\CurrentImpersonation;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Models\OwnerProfile;

/**
 * The owner portal's "records linked to my user" path (ARCHITECTURE.md
 * § 4, P03-15), mirroring the tenant portal's PortalTenancies: the only
 * way an /owner page reaches organization data. An owner's buildings may
 * be managed by several organizations (agencies that invited the owner, or
 * that took the buildings over in a management handover, P03-14), so the
 * portal sets no organization context of its own. Instead:
 *
 * 1. one unscoped read of `owner_profiles` (`organization_id`, `id`) by
 *    `user_id` (LinkedOwnerProfiles::byOrganization; archived profiles
 *    left out) — the P01-04 lookup pattern (orchestrator decision 2): no
 *    scope is removed and nothing else is read unscoped;
 * 2. for each of those organizations, OrganizationContext::run with the
 *    scope in place: the profiles are loaded again through the scope and
 *    kept only when OwnerProfilePolicy::viewAsOwner allows them (still
 *    linked to this user, not archived, and the user holds
 *    `property-owner` there);
 * 3. everything else (buildings, units, leases, bills) is read inside that
 *    same context (OwnerPortalRecords), limited to those profiles'
 *    buildings: buildings and units from this module, leases and bills
 *    through the Leasing and Billing contracts (OwnerLeases, OwnerBills),
 *    tenants' names through the Tenants contract.
 *
 * Record pages find their record with first(): it must be found among the
 * buildings of the user's own profiles, so another owner's building (or a
 * lease or bill of it), or another organization's, answers the same 404 as
 * a missing one.
 *
 * While GoProperty staff impersonate the user, only the support session's
 * organization is opened (PLATFORM_CONSOLE.md § 6 "Scope").
 */
final readonly class OwnerPortfolios
{
    public function __construct(
        private LinkedOwnerProfiles $linked,
        private OrganizationContext $context,
        private CurrentImpersonation $impersonation,
    ) {}

    /**
     * Every organization that manages buildings of the user (that they may
     * open now), by name.
     *
     * @return list<OwnerPortfolio>
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

        $portfolios = [];

        foreach (Organization::query()->whereKey(array_keys($profiles))->orderBy('name')->orderBy('id')->get() as $organization) {
            $ids = $this->context->run($organization, static fn (): array => array_values(OwnerProfile::query()
                ->whereKey($profiles[$organization->id])
                ->orderBy('id')
                ->get()
                ->filter(static fn (OwnerProfile $owner): bool => Gate::forUser($user)->allows('viewAsOwner', $owner))
                ->map(static fn (OwnerProfile $owner): int => $owner->id)
                ->all()));

            if ($ids !== []) {
                $portfolios[] = new OwnerPortfolio($organization, $ids);
            }
        }

        return $portfolios;
    }

    /**
     * $callback for each portfolio, run inside its organization.
     *
     * @template T
     *
     * @param  Closure(OwnerPortfolio): T  $callback
     * @return list<T>
     */
    public function map(User $user, Closure $callback): array
    {
        return array_map(
            fn (OwnerPortfolio $portfolio): mixed => $this->context->run($portfolio->organization, static fn (): mixed => $callback($portfolio)),
            $this->of($user),
        );
    }

    /**
     * The first non-null answer of $callback, run inside each portfolio's
     * organization in turn; null when no portfolio has it (the page
     * answers 404).
     *
     * @template T
     *
     * @param  Closure(OwnerPortfolio): (T|null)  $callback
     * @return T|null
     */
    public function first(User $user, Closure $callback): mixed
    {
        foreach ($this->of($user) as $portfolio) {
            $found = $this->context->run($portfolio->organization, static fn (): mixed => $callback($portfolio));

            if ($found !== null) {
                return $found;
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
