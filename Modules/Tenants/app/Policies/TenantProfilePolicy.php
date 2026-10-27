<?php

declare(strict_types=1);

namespace Modules\Tenants\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Portal\PortalTenancies;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Tenants' records in the current organization (ARCHITECTURE.md § 5).
 * Workspace members holding `tenants.view` (org-owner, org-admin, agent)
 * see them, archived ones too, ID numbers masked; `tenants.manage` (the
 * same three: an agent signing a lease adds its tenant) adds, edits,
 * archives and restores (through Profiles\TenantProfiles). Property owners
 * and tenants never open the workspace and hold neither. Another
 * organization's tenants never reach a policy: the organization scope
 * hides them. Imports write tenants under `imports.manage`
 * (ImportBatchPolicy), not these.
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard), and while GoProperty staff
 * impersonate, archiving is refused (`delete`, ImpersonationGuard) and so
 * is exporting the list (`export`: people's contact details).
 *
 * Agent narrowing (P02-03, decision 9): through Access\TenantNarrowing on
 * Modules\Properties\Contracts\BuildingAccess. Tenants have no building
 * until leases exist (P03-03a), so a narrowed agent sees every tenant for
 * now; a tenant outside the agent's buildings will answer 404
 * (Response::denyAsNotFound), checked before the ability's own rule.
 *
 * The tenant portal (P03-11a): viewAsTenant / viewInvoiceAsTenant, the
 * tenant's own record from their portal account (no workspace role).
 */
final class TenantProfilePolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantNarrowing $narrowing,
        private readonly PortalTenancies $tenancies,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewTenants);
    }

    /**
     * Export the tenants list (P02-07; Lists\TenantList, already narrowed).
     * It holds people's contact details, so GoProperty staff may look at
     * the list while impersonating but never take a copy away
     * (PLATFORM_CONSOLE.md § 6).
     */
    #[BlockedWhileImpersonating]
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, TenantProfile $tenant): Response|bool
    {
        if (! $this->belongsHere($tenant)) {
            return false;
        }

        if (! $this->narrowing->allows($user, $tenant)) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageTenants);
    }

    public function update(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: false);
    }

    /** Archive (soft delete; AGENT.md § 3 rule 7). */
    public function delete(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: false);
    }

    /** Bring an archived tenant back. */
    public function restore(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: true);
    }

    /**
     * The tenant's portal access (P03-01b, Modules\Tenants\Portal):
     * invite, send again, revoke, create or revoke a one-time sign-in link,
     * confirm the phone number in person. `tenants.portal_access`
     * (org-owner, org-admin, agent), active tenants only, narrowed like the
     * record. These decide who signs in to an account, so they are never
     * done while GoProperty staff impersonate (the permission is blocked
     * too) nor in a suspended organization (not a read).
     */
    #[BlockedWhileImpersonating]
    public function managePortalAccess(User $user, TenantProfile $tenant): Response|bool
    {
        if (! $this->belongsHere($tenant)) {
            return false;
        }

        if (! $this->narrowing->allows($user, $tenant)) {
            return Response::denyAsNotFound();
        }

        return ! $tenant->trashed() && $this->holds($user, OrganizationPermission::ManageTenantPortalAccess);
    }

    /**
     * The tenant portal (P03-11a, Modules\Tenants\Portal\PortalTenancies):
     * the user's own tenant record, proven by their portal account
     * (`tenant_profiles.user_id`, P03-01b) and not archived. Tenants hold no
     * workspace role (P03 decision 4), so this identity is the permission;
     * every portal page checks it for the record it shows (a bill or lease
     * through its tenant). Anyone else's record, or one of another
     * organization, answers 404. A read: allowed in a suspended organization
     * (the portal stays read-only there, ONBOARDING_COMPLIANCE.md § 3) and in
     * a support session, but only for the session's organization
     * (PLATFORM_CONSOLE.md § 6 "Scope").
     */
    public function viewAsTenant(User $user, TenantProfile $tenant): Response|bool
    {
        if (! $this->belongsHere($tenant)
            || $tenant->trashed()
            || $tenant->user_id === null
            || (int) $tenant->user_id !== (int) $user->id
            || ! $this->tenancies->opens($user, $tenant->organization_id)) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    /**
     * A bill's invoice as printed, from the portal (P03-11a): the tenant's
     * own record, as viewAsTenant. The printed invoice carries the
     * tenant's phone and email, so it is not opened while GoProperty staff
     * impersonate (as BillPolicy::viewInvoice, PLATFORM_CONSOLE.md § 6);
     * the bill page stays visible.
     */
    #[BlockedWhileImpersonating]
    public function viewInvoiceAsTenant(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->viewAsTenant($user, $tenant);
    }

    private function managed(User $user, TenantProfile $tenant, bool $archived): Response|bool
    {
        if (! $this->belongsHere($tenant)) {
            return false;
        }

        if (! $this->narrowing->allows($user, $tenant)) {
            return Response::denyAsNotFound();
        }

        return $tenant->trashed() === $archived && $this->create($user);
    }

    private function belongsHere(TenantProfile $tenant): bool
    {
        return $this->context->has() && $tenant->organization_id === $this->context->id();
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
