<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Members\RoleGrants;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Members of the current organization (Settings → Members, ARCHITECTURE.md
 * § 5). Listing them needs `members.view`; changing a member's roles or
 * removing them needs `members.manage` and the right to grant every
 * workspace role involved, the member's current ones and the new ones
 * (RoleGrants: an administrator never changes or removes an owner). The
 * last owner is protected by App\Members\Members.
 *
 * Always blocked while GoProperty staff impersonate a user (`remove…` is a
 * delete ability; the others are marked); a suspended organization refuses
 * them through the central guard.
 */
final class MembershipPolicy
{
    public function __construct(private readonly RoleGrants $grants) {}

    public function viewAny(User $user): bool
    {
        try {
            return $user->hasPermissionTo(OrganizationPermission::ViewMembers->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** @param  list<OrganizationRole>  $roles */
    #[BlockedWhileImpersonating]
    public function changeRoles(User $user, Membership $membership, array $roles): bool
    {
        $member = $membership->user;

        return $roles !== [] && $this->grants->mayGrantAll($user, [...$this->grants->memberRolesOf($member), ...$roles]);
    }

    #[BlockedWhileImpersonating]
    public function remove(User $user, Membership $membership): bool
    {
        return $this->grants->mayGrantAll($user, $this->grants->memberRolesOf($membership->user));
    }
}
