<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

/**
 * Where a customer lands after signing in, and the organization they chose
 * last (ONBOARDING_COMPLIANCE.md § 1 "After login"):
 *
 * - one workspace (an organization where their role opens /o/{slug}): it;
 * - several: the one they switched to last (`organization_user.is_default`),
 *   else the organization picker;
 * - none: the portal the user belongs in (PortalLanding: a tenant goes to
 *   /portal, P03-01b; an owner to /owner, P03-15), else the dashboard (the
 *   organization picker, which lists the portals of someone who belongs in
 *   several and sends someone with no organization at all to signup).
 */
final class WorkspaceLanding
{
    public function __construct(private readonly PortalLanding $portals) {}

    public function organizationFor(User $user): ?Organization
    {
        $organizations = $user->workspaceOrganizations()->get();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        return $organizations->first(fn (Organization $organization): bool => (bool) $organization->getRelationValue('pivot')?->getAttribute('is_default'));
    }

    public function url(User $user): string
    {
        $organization = $this->organizationFor($user);

        if ($organization !== null) {
            return route('workspace.home', $organization);
        }

        if ($user->workspaceOrganizationIds() === []) {
            $portal = $this->portals->landing($user);

            if ($portal !== null) {
                return $portal;
            }
        }

        return Fortify::redirects('login');
    }

    /**
     * Makes the organization the one the user lands on. Exactly one default
     * per user; the caller has checked membership (OrganizationPolicy).
     */
    public function remember(User $user, Organization $organization): void
    {
        DB::transaction(function () use ($user, $organization): void {
            DB::table('organization_user')
                ->where('user_id', $user->getKey())
                ->where('organization_id', '!=', $organization->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false, 'updated_at' => now()]);

            DB::table('organization_user')
                ->where('user_id', $user->getKey())
                ->where('organization_id', $organization->getKey())
                ->update(['is_default' => true, 'updated_at' => now()]);
        });
    }
}
