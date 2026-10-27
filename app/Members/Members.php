<?php

declare(strict_types=1);

namespace App\Members;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Members of the current organization (ARCHITECTURE.md § 5): change a
 * member's workspace roles, remove a member. Who may touch which role is
 * RoleGrants (checked through MembershipPolicy for the acting user, so the
 * suspended-organization and impersonation rules apply); the organization
 * always keeps at least one `org-owner`. Every change is audited (log
 * `members`).
 *
 * Removing a member deletes their membership and every role and direct
 * permission they hold in this organization (`model_has_roles` /
 * `model_has_permissions` rows with its `organization_id`), and nothing
 * they hold elsewhere; MemberRemoved lets modules drop what else the member
 * had there (building assignments), in the same transaction.
 */
final readonly class Members
{
    public function __construct(
        private OrganizationContext $context,
        private RoleGrants $grants,
        private MemberAudit $audit,
    ) {}

    /**
     * Sets the member's workspace roles. Roles outside the workspace set
     * (tenant, property owner) are left as they are.
     *
     * @param  list<OrganizationRole>  $roles
     *
     * @throws MemberChangeRefused
     */
    public function changeRoles(User $actor, Membership $membership, array $roles): void
    {
        $roles = array_values(array_filter(RoleGrants::memberRoles(), static fn (OrganizationRole $role): bool => in_array($role, $roles, true)));

        if ($roles === []) {
            throw new MemberChangeRefused('Choose at least one role. To take someone out of the organization, remove them instead.');
        }

        Gate::forUser($actor)->authorize('changeRoles', [$membership, $roles]);
        $organization = $this->context->get();

        DB::transaction(function () use ($actor, $membership, $roles, $organization): void {
            $this->lock($organization);
            $member = $this->memberOf($membership);
            $before = $this->grants->memberRolesOf($member, $organization);

            if ($before === $roles) {
                return;
            }

            foreach (array_diff_key($this->byValue($before), $this->byValue($roles)) as $role) {
                $member->removeRole($role->value);
            }

            $added = array_values(array_diff_key($this->byValue($roles), $this->byValue($before)));

            if ($added !== []) {
                $member->assignRole(...array_map(static fn (OrganizationRole $role): string => $role->value, $added));
            }

            $this->ensureAnOwnerRemains($organization);

            $this->audit->record('member.roles_changed', $organization, $actor, "Changed the roles of {$member->name}", [
                'user_id' => $member->id,
                'old' => ['roles' => $this->values($before)],
                'attributes' => ['roles' => $this->values($roles)],
            ]);
        });
    }

    /**
     * Takes the member out of the organization: membership, roles and
     * direct permissions here. Their account and other organizations stay.
     *
     * @throws MemberChangeRefused
     */
    public function remove(User $actor, Membership $membership): void
    {
        Gate::forUser($actor)->authorize('remove', $membership);
        $organization = $this->context->get();

        DB::transaction(function () use ($actor, $membership, $organization): void {
            $this->lock($organization);
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $member = $this->memberOf($locked);
            $roles = $member->roleNamesIn($organization);

            DB::table('model_has_roles')
                ->where('organization_id', $organization->id)
                ->where('model_type', $member->getMorphClass())
                ->where('model_id', $member->id)
                ->delete();
            DB::table('model_has_permissions')
                ->where('organization_id', $organization->id)
                ->where('model_type', $member->getMorphClass())
                ->where('model_id', $member->id)
                ->delete();
            $locked->delete();
            $member->unsetRelation('roles')->unsetRelation('permissions');

            $this->ensureAnOwnerRemains($organization);

            $this->audit->record('member.removed', $organization, $actor, "Removed {$member->name} from the organization", [
                'user_id' => $member->id,
                'old' => ['roles' => $roles],
            ]);

            // Modules clean up what the member had here (e.g. building assignments).
            MemberRemoved::dispatch($organization->id, $member->id, $actor->id);
        });
    }

    /** Members holding `org-owner` here. */
    public function ownerCount(?Organization $organization = null): int
    {
        $organization ??= $this->context->get();

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('organization_user', function ($join): void {
                $join->on('organization_user.user_id', '=', 'model_has_roles.model_id')
                    ->on('organization_user.organization_id', '=', 'model_has_roles.organization_id');
            })
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.organization_id', $organization->id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->count();
    }

    /**
     * Ids of the members whose roles here grant the permission (e.g. who
     * may approve a receipt void, P03-10b), in id order. Direct permissions
     * are not used by the customer roles (ARCHITECTURE.md § 5), so roles
     * decide.
     *
     * @return list<int>
     */
    public function holdersOf(OrganizationPermission $permission, ?Organization $organization = null): array
    {
        $organization ??= $this->context->get();

        $ids = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('organization_user', function ($join): void {
                $join->on('organization_user.user_id', '=', 'model_has_roles.model_id')
                    ->on('organization_user.organization_id', '=', 'model_has_roles.organization_id');
            })
            ->where('permissions.name', $permission->value)
            ->where('permissions.guard_name', OrganizationPermission::GUARD)
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.organization_id', $organization->id)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->distinct()
            ->orderBy('model_has_roles.model_id')
            ->pluck('model_has_roles.model_id')
            ->all();

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    /** @throws MemberChangeRefused */
    private function ensureAnOwnerRemains(Organization $organization): void
    {
        if ($this->ownerCount($organization) < 1) {
            // Rolls the transaction back: nothing changed.
            throw new MemberChangeRefused('The organization must keep at least one owner. Make someone else an owner first.');
        }
    }

    /** Serialises member changes per organization, so two removals cannot both pass the owner check. */
    private function lock(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
    }

    private function memberOf(Membership $membership): User
    {
        return User::query()->findOrFail($membership->user_id);
    }

    /**
     * @param  list<OrganizationRole>  $roles
     * @return array<string, OrganizationRole>
     */
    private function byValue(array $roles): array
    {
        $keyed = [];

        foreach ($roles as $role) {
            $keyed[$role->value] = $role;
        }

        return $keyed;
    }

    /**
     * @param  list<OrganizationRole>  $roles
     * @return list<string>
     */
    private function values(array $roles): array
    {
        return array_map(static fn (OrganizationRole $role): string => $role->value, $roles);
    }
}
