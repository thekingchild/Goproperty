<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\RoleGrants;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Member invitations in the current organization (Settings → Members,
 * ARCHITECTURE.md § 5). Seeing them needs `members.view`; inviting,
 * sending again and revoking need `members.manage` and the right to grant
 * every role involved (RoleGrants: only an owner invites an owner).
 * Invitations of another organization are never found (organization scope),
 * so they answer 404 before any policy runs.
 *
 * Member changes are always blocked while GoProperty staff impersonate a
 * user (PLATFORM_CONSOLE.md § 6), through `members.manage` and the marks
 * here; a suspended organization refuses them through the central guard.
 */
final class InvitationPolicy
{
    public function __construct(private readonly RoleGrants $grants) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewMembers);
    }

    /** @param  list<OrganizationRole>  $roles */
    #[BlockedWhileImpersonating]
    public function create(User $user, array $roles): bool
    {
        return $roles !== [] && $this->grants->mayGrantAll($user, $roles);
    }

    #[BlockedWhileImpersonating]
    public function resend(User $user, Invitation $invitation): bool
    {
        return $this->grants->mayGrantAll($user, $invitation->roleEnums());
    }

    #[BlockedWhileImpersonating]
    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->grants->mayGrantAll($user, $invitation->roleEnums());
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
