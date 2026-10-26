<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\AuditEntry;
use App\Platform\Staff\DefaultStaffRole;
use App\Platform\Staff\StaffPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A staff role (PLATFORM_CONSOLE.md § 1): a spatie role on the `staff`
 * guard with no organization. Only staff roles are visible through this
 * model, so the console can never list or edit a customer role. Changes go
 * through App\Platform\Staff\StaffRoles, which audits them.
 */
class StaffRole extends Role
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] = StaffPermission::GUARD;

        parent::__construct($attributes);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('staff', fn (Builder $query) => $query
            ->where($query->qualifyColumn('guard_name'), StaffPermission::GUARD)
            ->whereNull($query->qualifyColumn('organization_id')));

        // Staff roles are global, whatever context the code runs in (also
        // set by the constructor, for seeders that mute model events).
        static::creating(function (self $role): void {
            $role->guard_name = StaffPermission::GUARD;
            $role->organization_id = null;
        });
    }

    public function defaultRole(): ?DefaultStaffRole
    {
        return $this->is_default ? DefaultStaffRole::tryFrom($this->name) : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === DefaultStaffRole::SuperAdmin->value && $this->is_default;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_values($this->permissions()->pluck('name')->map(fn (mixed $name): string => (string) $name)->all());
    }

    /** True when the role carries a protected permission (or is super-admin). */
    public function carriesProtectedPermission(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $protected = array_map(static fn (StaffPermission $permission): string => $permission->value, StaffPermission::protectedCases());

        return array_intersect($this->permissionNames(), $protected) !== [];
    }

    /**
     * Role changes in the audit log, for the role's history.
     *
     * @return MorphMany<AuditEntry, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'subject');
    }
}
