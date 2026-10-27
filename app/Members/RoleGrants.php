<?php

declare(strict_types=1);

namespace App\Members;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Who may grant which role in an organization (ARCHITECTURE.md § 5,
 * decided in P01-04):
 *
 * - Settings → Members manages the workspace roles only: `org-owner`,
 *   `org-admin` and `agent`. Tenants (P03-01b) and agency-invited property
 *   owners have their own invitations; a member's tenant or property-owner
 *   role in the same organization is never touched here.
 * - Granting, changing or taking away any of them needs `members.manage`
 *   (owners and administrators).
 * - Only an `org-owner` may grant or take away `org-owner`, so an
 *   administrator can invite, change and remove administrators and agents
 *   but never makes an owner, and never changes or removes one.
 * - The organization always keeps at least one owner (App\Members\Members).
 *
 * `members.manage` is always blocked while GoProperty staff impersonate a
 * user and is withdrawn while the organization is suspended, so neither
 * can change members (OrganizationPermission).
 */
final readonly class RoleGrants
{
    public function __construct(private OrganizationContext $context) {}

    /** @return list<OrganizationRole> */
    public static function memberRoles(): array
    {
        return array_values(array_filter(OrganizationRole::cases(), static fn (OrganizationRole $role): bool => $role->opensWorkspace()));
    }

    /**
     * The workspace roles the user may grant or take away in the
     * organization (the one given, else the current one).
     *
     * @return list<OrganizationRole>
     */
    public function grantableBy(User $user, ?Organization $organization = null): array
    {
        $organization ??= $this->context->get();

        return $this->context->run($organization, function () use ($user, $organization): array {
            try {
                if (! $user->hasPermissionTo(OrganizationPermission::ManageMembers->value, OrganizationPermission::GUARD)) {
                    return [];
                }
            } catch (PermissionDoesNotExist) {
                return [];
            }

            return $user->hasRoleIn($organization, OrganizationRole::Owner)
                ? self::memberRoles()
                : array_values(array_filter(self::memberRoles(), static fn (OrganizationRole $role): bool => $role !== OrganizationRole::Owner));
        });
    }

    /** @param  list<OrganizationRole>  $roles */
    public function mayGrantAll(User $user, array $roles, ?Organization $organization = null): bool
    {
        $grantable = $this->grantableBy($user, $organization);

        if ($grantable === []) {
            return false;
        }

        foreach ($roles as $role) {
            if (! in_array($role, $grantable, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The workspace roles a user holds in the current organization.
     *
     * @return list<OrganizationRole>
     */
    public function memberRolesOf(User $user, ?Organization $organization = null): array
    {
        $names = $user->roleNamesIn($organization ?? $this->context->get());

        return array_values(array_filter(self::memberRoles(), static fn (OrganizationRole $role): bool => in_array($role->value, $names, true)));
    }
}
