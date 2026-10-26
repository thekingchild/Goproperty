<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Models\Role;
use App\Platform\Staff\DefaultStaffRole;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff roles on the `staff` guard (PLATFORM_CONSOLE.md § 1). They are
 * global: no organization on the role or on the assignment. Spatie takes the
 * team from the current OrganizationContext, which the console never sets,
 * but a staff check can happen inside one (e.g. while impersonating, P01-08),
 * so every staff role read and grant runs outside the organization context.
 *
 * Grants and removals in the console go through App\Platform\Staff\StaffRoles
 * (audit, two-person rules, super-admin floor); these methods are the
 * storage underneath.
 *
 * A permission check (Gate, `can()`, policies) answers from the catalogue
 * (StaffPermission) and the staff member's active roles, and only for an
 * active account with 2FA enrolled: without 2FA a staff member holds no
 * console permission at all, whatever their roles.
 *
 * @phpstan-require-extends Model
 */
trait HasStaffRoles
{
    use HasRoles {
        roles as private spatieRoles;
        permissions as private spatiePermissions;
        assignRole as private spatieAssignRole;
        removeRole as private spatieRemoveRole;
        syncRoles as private spatieSyncRoles;
        givePermissionTo as private spatieGivePermissionTo;
        revokePermissionTo as private spatieRevokePermissionTo;
    }

    /** @return BelongsToMany<Model, $this> */
    public function roles(): BelongsToMany
    {
        return app(OrganizationContext::class)->outside(fn (): BelongsToMany => $this->spatieRoles());
    }

    /** @return BelongsToMany<Model, $this> */
    public function permissions(): BelongsToMany
    {
        return app(OrganizationContext::class)->outside(fn (): BelongsToMany => $this->spatiePermissions());
    }

    /**
     * @param  mixed  ...$roles
     * @return $this
     */
    public function assignRole(...$roles): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieAssignRole(...$roles));
    }

    /**
     * @param  mixed  ...$role
     * @return $this
     */
    public function removeRole(...$role): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieRemoveRole(...$role));
    }

    /**
     * @param  mixed  ...$roles
     * @return $this
     */
    public function syncRoles(...$roles): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieSyncRoles(...$roles));
    }

    /** @param  mixed  ...$permissions */
    public function givePermissionTo(...$permissions): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieGivePermissionTo(...$permissions));
    }

    /** @param  mixed  $permission */
    public function revokePermissionTo($permission): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieRevokePermissionTo($permission));
    }

    /**
     * Spatie's check, replaced: see holdsStaffPermission().
     *
     * @param  mixed  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $name = match (true) {
            $permission instanceof BackedEnum => (string) $permission->value,
            $permission instanceof Permission => (string) $permission->getAttribute('name'),
            is_string($permission) => $permission,
            default => null,
        };

        return $name !== null && ($guardName === null || $guardName === StaffPermission::GUARD) && $this->holdsStaffPermission($name);
    }

    public function holdsStaffPermission(StaffPermission|string $permission): bool
    {
        $permission = $permission instanceof StaffPermission ? $permission : StaffPermission::tryFrom($permission);

        if ($permission === null || ! $this->mayUseStaffPermissions()) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($this->activeStaffRoles() as $role) {
            if ($role->permissions->contains(fn (Model $held): bool => $held->getAttribute('name') === $permission->value)) {
                return true;
            }
        }

        return false;
    }

    /** Holds the active, shipped `super-admin` role (status is checked separately). */
    public function isSuperAdmin(): bool
    {
        return $this->activeStaffRoles()->contains(fn (Role $role): bool => $role->is_default && $role->name === DefaultStaffRole::SuperAdmin->value);
    }

    /**
     * The staff member's roles that still grant anything.
     *
     * @return Collection<int, Role>
     */
    public function activeStaffRoles(): Collection
    {
        $this->loadMissing('roles.permissions');

        /** @var Collection<int, Role> $roles */
        $roles = $this->getRelation('roles')
            ->filter(fn (mixed $role): bool => $role instanceof Role && $role->active && $role->guard_name === StaffPermission::GUARD)
            ->values();

        return collect($roles->all());
    }

    /**
     * Every catalogue permission the staff member holds now.
     *
     * @return list<string>
     */
    public function staffPermissionNames(): array
    {
        $names = [];

        foreach (StaffPermission::cases() as $permission) {
            if ($this->holdsStaffPermission($permission)) {
                $names[] = $permission->value;
            }
        }

        return $names;
    }

    /** Fresh roles on the next check (e.g. after a grant in the same request). */
    public function forgetStaffRoles(): void
    {
        $this->unsetRelation('roles');
    }

    abstract protected function mayUseStaffPermissions(): bool;
}
