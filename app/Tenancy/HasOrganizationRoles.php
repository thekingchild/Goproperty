<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Role;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * Roles per organization for customers (ARCHITECTURE.md § 5): spatie roles
 * with teams, where the team is always the current OrganizationContext
 * (OrganizationTeamResolver). On top of spatie this:
 *
 * - forgets roles and permissions loaded for another organization, so a
 *   model kept across OrganizationContext::run() calls (or restored in a
 *   queued job) never answers with another organization's roles;
 * - refuses role grants outside an organization, to a non-member, or of a
 *   role another organization defined for itself;
 * - holds no write permission while the organization is suspended or
 *   cancelled (OrganizationWriteGuard);
 * - holds none of the always-blocked permissions (and, in a read-only
 *   session, no write permission) while GoProperty staff impersonate the
 *   user (ImpersonationGuard).
 *
 * @phpstan-require-extends Model
 */
trait HasOrganizationRoles
{
    use HasRoles {
        assignRole as private spatieAssignRole;
        givePermissionTo as private spatieGivePermissionTo;
        hasPermissionTo as private spatieHasPermissionTo;
    }

    /**
     * The organization each loaded roles/permissions relation was loaded
     * for (null: none).
     *
     * @var array<string, int|string|null>
     */
    private array $permissionTeams = [];

    /** @return BelongsToMany<Organization, $this> */
    abstract public function organizations(): BelongsToMany;

    /**
     * @param  string  $relation
     * @param  mixed  $value
     * @return $this
     */
    public function setRelation($relation, $value)
    {
        if ($relation === 'roles' || $relation === 'permissions') {
            $this->permissionTeams[$relation] = getPermissionsTeamId();
        }

        return parent::setRelation($relation, $value);
    }

    /**
     * @param  string  $key
     * @return mixed
     */
    public function getRelationValue($key)
    {
        if (($key === 'roles' || $key === 'permissions')
            && $this->relationLoaded($key)
            && ($this->permissionTeams[$key] ?? null) !== getPermissionsTeamId()) {
            $this->unsetRelation($key);
        }

        return parent::getRelationValue($key);
    }

    /**
     * Spatie's assignRole(), inside the current organization only.
     *
     * @param  mixed  ...$roles
     * @return $this
     */
    public function assignRole(...$roles): static
    {
        $this->ensureCanBeGrantedInCurrentOrganization();

        $foreign = Role::query()
            ->whereKey($this->collectRoles($roles))
            ->whereNotNull('organization_id')
            ->where('organization_id', '!=', getPermissionsTeamId())
            ->exists();

        if ($foreign) {
            throw new CrossOrganizationWrite('A role another organization defined cannot be assigned in this one.');
        }

        return $this->spatieAssignRole(...$roles);
    }

    /**
     * Spatie's check, except that a suspended or cancelled organization's
     * members keep only the permissions that read, export or pay
     * (OrganizationPermission::allowedWhileSuspended()).
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

        // A permission given by ID is refused too: fail closed.
        if (! app(OrganizationWriteGuard::class)->allowsPermission($name ?? '')
            || ! app(ImpersonationGuard::class)->allowsPermission($this, $name ?? '')) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * Spatie's givePermissionTo(), inside the current organization only.
     *
     * @param  mixed  ...$permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        $this->ensureCanBeGrantedInCurrentOrganization();

        return $this->spatieGivePermissionTo(...$permissions);
    }

    /**
     * Adds the user to an organization (if not already a member) with the
     * given roles there. Roles the user holds elsewhere are untouched.
     */
    public function joinOrganization(Organization $organization, OrganizationRole ...$roles): void
    {
        $this->organizations()->syncWithoutDetaching([$organization->getKey()]);

        if ($roles !== []) {
            app(OrganizationContext::class)->run($organization, function () use ($roles): void {
                $this->assignRole(...array_map(static fn (OrganizationRole $role): string => $role->value, $roles));
            });
        }
    }

    /**
     * The user's roles in one organization, freshly read.
     *
     * @return list<string>
     */
    public function roleNamesIn(Organization $organization): array
    {
        return app(OrganizationContext::class)->run($organization, fn (): array => array_values(
            $this->roles()->orderBy('name')->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all(),
        ));
    }

    public function hasRoleIn(Organization $organization, OrganizationRole $role): bool
    {
        return in_array($role->value, $this->roleNamesIn($organization), true);
    }

    /**
     * Role names per organization for every organization the user is in,
     * in one query (for the organization switcher).
     *
     * @return array<int, list<string>>
     */
    public function roleNamesByOrganization(): array
    {
        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->whereNotNull('model_has_roles.organization_id')
            ->orderBy('roles.name')
            ->get(['model_has_roles.organization_id', 'roles.name']);

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->organization_id][] = (string) $row->name;
        }

        return $names;
    }

    /**
     * The organizations whose workspace the user may open: a member holding
     * `workspace.access` there, through a role or directly (ARCHITECTURE.md
     * § 5). A member who is only a tenant or property owner is not listed.
     *
     * @return list<int>
     */
    public function workspaceOrganizationIds(): array
    {
        $permission = static fn ($query) => $query
            ->where('permissions.name', OrganizationPermission::OpenWorkspace->value)
            ->where('permissions.guard_name', OrganizationPermission::GUARD);

        $viaRoles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->whereNotNull('model_has_roles.organization_id')
            ->where(fn ($query) => $query->whereNull('roles.organization_id')->orWhereColumn('roles.organization_id', 'model_has_roles.organization_id'))
            ->where($permission)
            ->select('model_has_roles.organization_id');

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $this->getMorphClass())
            ->where('model_has_permissions.model_id', $this->getKey())
            ->whereNotNull('model_has_permissions.organization_id')
            ->where($permission)
            ->select('model_has_permissions.organization_id');

        return array_values(DB::table('organization_user')
            ->where('user_id', $this->getKey())
            ->where(fn ($query) => $query->whereIn('organization_id', $viaRoles)->orWhereIn('organization_id', $direct))
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Organizations whose workspace the user may open (switcher, picker, landing).
     *
     * @return BelongsToMany<Organization, $this>
     */
    public function workspaceOrganizations(): BelongsToMany
    {
        return $this->organizations()->whereKey($this->workspaceOrganizationIds());
    }

    private function ensureCanBeGrantedInCurrentOrganization(): void
    {
        $context = app(OrganizationContext::class);

        if (! $context->has()) {
            throw MissingOrganizationContext::forModel(static::class);
        }

        if (! $this->organizations()->whereKey($context->id())->exists()) {
            throw new CrossOrganizationWrite(static::class.' must be a member of an organization before it is given a role there.');
        }
    }
}
