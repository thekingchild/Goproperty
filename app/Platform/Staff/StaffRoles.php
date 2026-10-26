<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles and grants (PLATFORM_CONSOLE.md § 1). The console calls these,
 * never spatie directly, so every rule holds in one place:
 *
 * - only staff holding `roles.manage` change roles or grants;
 * - protected permissions are added to a role only by a super-admin, and
 *   only once a second super-admin approves (two-person rule, § 1A);
 * - granting `super-admin`, `finance-admin`, `compliance-officer` (or any
 *   role carrying a protected permission) takes effect only when a second
 *   super-admin approves. One bootstrap exception: while only one active
 *   super-admin exists, nobody else could approve, so that super-admin's
 *   grant of `super-admin` applies at once (audited as such);
 * - the `super-admin` role is never edited or deactivated, and the number
 *   of active super-admins never drops below min(current, 2);
 * - every change is written to the audit log.
 */
final class StaffRoles
{
    public function __construct(
        private readonly StaffAudit $audit,
        private readonly ApprovalService $approvals,
        private readonly SuperAdminFloor $floor,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function superAdminRole(): ?StaffRole
    {
        return StaffRole::query()->where('name', DefaultStaffRole::SuperAdmin->value)->where('is_default', true)->first();
    }

    public function needsTwoPersonGrant(StaffRole $role): bool
    {
        return ($role->defaultRole()?->needsTwoPersonGrant() ?? false) || $role->carriesProtectedPermission();
    }

    /**
     * Grants a role, or opens an approval request when the grant needs a
     * second staff member. Returns the request in that case.
     */
    public function grant(PlatformStaff $actor, PlatformStaff $staff, StaffRole $role, ?string $reason = null): ?ApprovalRequest
    {
        $this->assertMayManageRoles($actor);
        $this->assertGrantable($staff, $role);

        if ($role->carriesProtectedPermission() && ! $actor->isSuperAdmin()) {
            throw new StaffChangeRefused('Only a super-admin can grant a role that carries protected permissions.');
        }

        if ($this->needsTwoPersonGrant($role) && ! $this->isBootstrapGrant($actor, $role)) {
            return $this->approvals->request(
                ApprovalAction::RoleGrant,
                $actor,
                $staff,
                "Grant {$role->name} to {$staff->name} ({$staff->email})",
                ['kind' => 'staff_role', 'role_id' => $role->id, 'role' => $role->name],
                $reason,
            );
        }

        $this->assign($staff, $role, $actor, [
            'reason' => $reason,
            'bootstrap' => $this->needsTwoPersonGrant($role) ? true : null,
        ]);

        return null;
    }

    public function revoke(PlatformStaff $actor, PlatformStaff $staff, StaffRole $role, ?string $reason = null): void
    {
        $this->assertMayManageRoles($actor);

        if (! $staff->roles()->whereKey($role->getKey())->exists()) {
            throw new StaffChangeRefused("{$staff->name} does not hold {$role->name}.");
        }

        $remove = function () use ($actor, $staff, $role, $reason): void {
            $staff->removeRole($role);
            $staff->forgetStaffRoles();

            $this->audit->record('staff.role_removed', $staff, $actor, "role {$role->name} removed from {$staff->email}", array_filter([
                'role' => $role->name,
                'role_id' => $role->id,
                'reason' => $reason,
            ], fn (mixed $value): bool => $value !== null));
        };

        $role->isSuperAdmin() ? $this->floor->guard($staff, $remove) : DB::transaction($remove);
    }

    /**
     * Carries out an approved `role_grant` request: a role for a staff
     * member, or protected permissions for a role.
     */
    public function applyApproved(ApprovalRequest $request, PlatformStaff $approver): void
    {
        $payload = $request->payload ?? [];
        $kind = $payload['kind'] ?? null;

        if ($kind === 'staff_role') {
            $staff = $request->subject;
            $role = StaffRole::query()->whereKey((int) ($payload['role_id'] ?? 0))->first();

            if (! $staff instanceof PlatformStaff || $role === null) {
                throw new ApprovalRefused('The staff member or role no longer exists.');
            }

            try {
                $this->assertGrantable($staff, $role);
            } catch (StaffChangeRefused $exception) {
                throw new ApprovalRefused($exception->getMessage());
            }

            $this->assign($staff, $role, $approver, [
                'approval_request_id' => $request->id,
                'requested_by_staff_id' => $request->requested_by_staff_id,
                'reason' => $request->reason,
            ]);

            return;
        }

        if ($kind === 'role_permissions') {
            $role = $request->subject;
            $permissions = $this->catalogueNames(is_array($payload['permissions'] ?? null) ? $payload['permissions'] : []);

            if (! $role instanceof StaffRole || $role->is_protected || ! $role->active) {
                throw new ApprovalRefused('The role no longer exists or can no longer be changed.');
            }

            $added = array_values(array_diff($permissions, $role->permissionNames()));
            $role->givePermissionTo($added);
            $this->registrar->forgetCachedPermissions();

            $this->audit->record('role.permissions_added', $role, $approver, "protected permissions added to role {$role->name}", [
                'permissions_added' => $added,
                'approval_request_id' => $request->id,
                'requested_by_staff_id' => $request->requested_by_staff_id,
            ]);

            return;
        }

        throw new ApprovalRefused('This request cannot be carried out.');
    }

    /**
     * A new custom role. Protected permissions in the list wait for a
     * second super-admin (the returned request); the rest apply now.
     *
     * @param  list<string>  $permissions
     * @return array{0: StaffRole, 1: ApprovalRequest|null}
     */
    public function createRole(PlatformStaff $actor, string $name, ?string $description, array $permissions): array
    {
        $this->assertMayManageRoles($actor);
        $name = $this->validName($name);
        $permissions = $this->catalogueNames($permissions);
        [$now, $protected] = $this->splitProtected($permissions);

        if ($protected !== [] && ! $actor->isSuperAdmin()) {
            throw new StaffChangeRefused('Only a super-admin can add protected permissions to a role.');
        }

        $role = DB::transaction(function () use ($actor, $name, $description, $now): StaffRole {
            $role = new StaffRole;
            $role->forceFill([
                'name' => $name,
                'description' => $this->cleanDescription($description),
                'is_default' => false,
                'is_protected' => false,
                'active' => true,
            ])->save();
            $role->syncPermissions($now);
            $this->registrar->forgetCachedPermissions();

            $this->audit->record('role.created', $role, $actor, "role {$name} created", [
                'description' => $role->description,
                'permissions' => $now,
            ]);

            return $role;
        });

        return [$role, $this->requestProtected($actor, $role, $protected)];
    }

    /**
     * Renames (custom roles only), re-describes and changes permissions.
     * Removals and ordinary additions apply now; protected additions wait
     * for a second super-admin (the returned request).
     *
     * @param  list<string>  $permissions
     */
    public function updateRole(PlatformStaff $actor, StaffRole $role, string $name, ?string $description, array $permissions): ?ApprovalRequest
    {
        $this->assertMayManageRoles($actor);
        $this->assertEditable($role);

        $name = trim($name);

        if ($role->is_default && $name !== $role->name) {
            throw new StaffChangeRefused('Default roles keep their names; create a new role instead.');
        }

        $name = $this->validName($name, $role);
        $permissions = $this->catalogueNames($permissions);
        $current = $role->permissionNames();
        $added = array_values(array_diff($permissions, $current));
        $removed = array_values(array_diff($current, $permissions));
        [$addNow, $addLater] = $this->splitProtected($added);

        if ($addLater !== [] && ! $actor->isSuperAdmin()) {
            throw new StaffChangeRefused('Only a super-admin can add protected permissions to a role.');
        }

        DB::transaction(function () use ($actor, $role, $name, $description, $addNow, $removed): void {
            $changes = [];
            $description = $this->cleanDescription($description);

            if ($name !== $role->name) {
                $changes['name'] = ['old' => $role->name, 'new' => $name];
            }

            if ($description !== $role->description) {
                $changes['description'] = ['old' => $role->description, 'new' => $description];
            }

            $role->forceFill(['name' => $name, 'description' => $description])->save();

            if ($addNow !== []) {
                $role->givePermissionTo($addNow);
                $changes['permissions_added'] = $addNow;
            }

            foreach ($removed as $permission) {
                $role->revokePermissionTo($permission);
            }

            if ($removed !== []) {
                $changes['permissions_removed'] = $removed;
            }

            $this->registrar->forgetCachedPermissions();

            if ($changes !== []) {
                $this->audit->record('role.updated', $role, $actor, "role {$role->name} changed", $changes);
            }
        });

        return $this->requestProtected($actor, $role, $addLater);
    }

    /** Retires a role nobody holds any more (§ 1: staff are moved first). */
    public function deactivateRole(PlatformStaff $actor, StaffRole $role): void
    {
        $this->assertMayManageRoles($actor);
        $this->assertEditable($role);

        $holders = $role->users()->count();

        if ($holders > 0) {
            throw new StaffChangeRefused("{$holders} staff ".($holders === 1 ? 'member holds' : 'members hold').' this role. Move them to another role first.');
        }

        $role->forceFill(['active' => false])->save();
        $this->registrar->forgetCachedPermissions();
        $this->audit->record('role.deactivated', $role, $actor, "role {$role->name} deactivated");
    }

    public function reactivateRole(PlatformStaff $actor, StaffRole $role): void
    {
        $this->assertMayManageRoles($actor);
        $this->assertEditable($role);

        $role->forceFill(['active' => true])->save();
        $this->registrar->forgetCachedPermissions();
        $this->audit->record('role.reactivated', $role, $actor, "role {$role->name} reactivated");
    }

    /**
     * The installer's grant (FirstSuperAdmin): no actor, no approval.
     *
     * @param  array<string, mixed>  $properties
     */
    public function assignWithoutActor(PlatformStaff $staff, StaffRole $role, array $properties): void
    {
        if ($staff->roles()->whereKey($role->getKey())->exists()) {
            return;
        }

        $this->assign($staff, $role, null, $properties);
    }

    /** @param  array<string, mixed>  $properties */
    private function assign(PlatformStaff $staff, StaffRole $role, ?PlatformStaff $actor, array $properties): void
    {
        DB::transaction(function () use ($staff, $role, $actor, $properties): void {
            $staff->assignRole($role);
            $staff->forgetStaffRoles();

            $this->audit->record('staff.role_granted', $staff, $actor, "role {$role->name} granted to {$staff->email}", array_filter([
                'role' => $role->name,
                'role_id' => $role->id,
                ...$properties,
            ], fn (mixed $value): bool => $value !== null));
        });
    }

    private function isBootstrapGrant(PlatformStaff $actor, StaffRole $role): bool
    {
        // Only the lone super-admin, and only while nobody else (active or
        // invited) holds the role: the exception makes the second one, no more.
        return $role->isSuperAdmin()
            && $actor->status === StaffStatus::Active
            && $actor->isSuperAdmin()
            && ! PlatformStaff::query()
                ->whereKeyNot($actor->getKey())
                ->where('status', '!=', StaffStatus::Deactivated)
                ->whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))
                ->exists();
    }

    /**
     * Opens the approval request for protected permissions on a role.
     *
     * @param  list<string>  $permissions
     */
    private function requestProtected(PlatformStaff $actor, StaffRole $role, array $permissions): ?ApprovalRequest
    {
        if ($permissions === []) {
            return null;
        }

        return $this->approvals->request(
            ApprovalAction::RoleGrant,
            $actor,
            $role,
            "Add protected permissions to role {$role->name}: ".implode(', ', $permissions),
            ['kind' => 'role_permissions', 'permissions' => $permissions],
        );
    }

    private function assertGrantable(PlatformStaff $staff, StaffRole $role): void
    {
        if (! $role->active) {
            throw new StaffChangeRefused("The role {$role->name} is deactivated.");
        }

        if ($staff->status === StaffStatus::Deactivated) {
            throw new StaffChangeRefused("{$staff->name} is deactivated. Reactivate them first.");
        }

        if ($staff->roles()->whereKey($role->getKey())->exists()) {
            throw new StaffChangeRefused("{$staff->name} already holds {$role->name}.");
        }
    }

    private function assertMayManageRoles(PlatformStaff $actor): void
    {
        if (! $actor->holdsStaffPermission(StaffPermission::RolesManage)) {
            throw new StaffChangeRefused('You do not have permission to manage roles.');
        }
    }

    private function assertEditable(StaffRole $role): void
    {
        if ($role->is_protected) {
            throw new StaffChangeRefused('The super-admin role cannot be changed or deactivated.');
        }
    }

    private function validName(string $name, ?StaffRole $except = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || preg_match('/^[\pL\pN][\pL\pN \-]*$/u', $name) !== 1) {
            throw new StaffChangeRefused('Use 2 to 60 letters, numbers, spaces or hyphens for the role name.');
        }

        $taken = StaffRole::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->pluck('name')
            ->contains(fn (mixed $existing): bool => mb_strtolower((string) $existing) === mb_strtolower($name));

        if ($taken) {
            throw new StaffChangeRefused("A role named {$name} already exists.");
        }

        return $name;
    }

    private function cleanDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : mb_substr($description, 0, 255);
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private function catalogueNames(array $names): array
    {
        $valid = [];

        foreach ($names as $name) {
            $permission = is_string($name) ? StaffPermission::tryFrom($name) : null;

            if ($permission === null) {
                throw new StaffChangeRefused('Unknown permission: '.(is_scalar($name) ? (string) $name : '?'));
            }

            $valid[] = $permission->value;
        }

        return array_values(array_unique($valid));
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitProtected(array $permissions): array
    {
        $now = [];
        $protected = [];

        foreach ($permissions as $permission) {
            if (StaffPermission::from($permission)->isProtected()) {
                $protected[] = $permission;
            } else {
                $now[] = $permission;
            }
        }

        return [$now, $protected];
    }
}
