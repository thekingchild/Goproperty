<?php

declare(strict_types=1);

namespace Modules\Properties\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\OwnerPortal\OwnerPortfolios;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Property owners' records in the current organization (ARCHITECTURE.md
 * § 5). Workspace members holding `owners.view` (org-owner, org-admin,
 * agent) see them, ID numbers masked; `owners.manage` (org-owner, org-admin)
 * adds, edits, archives and restores. Property owners and tenants never open
 * the workspace and hold neither. Another organization's owners never reach
 * a policy: the organization scope hides them.
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard), and while GoProperty staff
 * impersonate, archiving is refused (`delete`, ImpersonationGuard) and so
 * is exporting the list (`export`: people's contact details).
 *
 * Agent narrowing (P02-03): owners are not assigned themselves, so an agent
 * limited to some buildings sees the owners of those buildings only; any
 * other owner answers 404 (Response::denyAsNotFound).
 */
final class OwnerProfilePolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AgentNarrowing $narrowing,
        private readonly OwnerPortfolios $portfolios,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewOwners);
    }

    /**
     * Export the owners list (P02-07; Lists\OwnerList, already narrowed).
     * It holds people's contact details, so GoProperty staff may look at
     * the list while impersonating but never take a copy away
     * (PLATFORM_CONSOLE.md § 6).
     */
    #[BlockedWhileImpersonating]
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, OwnerProfile $owner): Response|bool
    {
        if (! $this->belongsHere($owner)) {
            return false;
        }

        $buildings = $this->narrowing->limitedTo($user);

        if ($buildings !== null && ! $owner->properties()->whereIn('properties.id', $buildings)->exists()) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageOwners);
    }

    public function update(User $user, OwnerProfile $owner): bool
    {
        return $this->belongsHere($owner) && ! $owner->trashed() && $this->create($user);
    }

    /** Archive (soft delete; AGENT.md § 3 rule 7). */
    public function delete(User $user, OwnerProfile $owner): bool
    {
        return $this->update($user, $owner);
    }

    /**
     * Invite the owner to the owner portal, send the invitation again,
     * revoke it, or remove the owner's access (P03-15,
     * OwnerPortal\OwnerInvitations). An active owner of this organization,
     * for members holding `owners.manage` (org-owner, org-admin). Never
     * while GoProperty staff impersonate (who may sign in to an account is
     * a credential matter, AGENT.md § 3 rule 9); the suspended guard refuses
     * it as a write.
     */
    #[BlockedWhileImpersonating]
    public function managePortalAccess(User $user, OwnerProfile $owner): bool
    {
        return $this->belongsHere($owner) && ! $owner->trashed() && $this->holds($user, OrganizationPermission::ManageOwners);
    }

    /**
     * The owner portal (P03-15, OwnerPortal\OwnerPortfolios): the user's own
     * owner record in this organization, proven by the link the owner's
     * invitation made (`owner_profiles.user_id`), not archived, and the
     * user holds `property-owner` here (removing the owner's access takes
     * both away). The portal shows only the buildings of the records this
     * allows. A read: allowed in a suspended organization and in a support
     * session, but only for the session's organization
     * (PLATFORM_CONSOLE.md § 6 "Scope"). Anything else answers 404.
     */
    public function viewAsOwner(User $user, OwnerProfile $owner): Response|bool
    {
        if (! $this->belongsHere($owner)
            || $owner->trashed()
            || $owner->user_id === null
            || (int) $owner->user_id !== (int) $user->id
            || ! $this->portfolios->opens($user, $owner->organization_id)
            || ! $user->hasRole(OrganizationRole::PropertyOwner->value, OrganizationPermission::GUARD)) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    /** Bring an archived owner back. */
    public function restore(User $user, OwnerProfile $owner): bool
    {
        return $this->belongsHere($owner) && $owner->trashed() && $this->create($user);
    }

    private function belongsHere(OwnerProfile $owner): bool
    {
        return $this->context->has() && $owner->organization_id === $this->context->id();
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
