<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff roles on the `staff` guard (PLATFORM_CONSOLE.md § 1). They are
 * global: no organization on the role or on the assignment. Spatie takes the
 * team from the current OrganizationContext, which the console never sets,
 * but a staff check can happen inside one (e.g. while impersonating, P01-08),
 * so every staff role read and grant runs outside the organization context.
 *
 * @phpstan-require-extends Model
 */
trait HasStaffRoles
{
    use HasRoles {
        roles as private spatieRoles;
        permissions as private spatiePermissions;
        assignRole as private spatieAssignRole;
        givePermissionTo as private spatieGivePermissionTo;
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

    /** @param  mixed  ...$permissions */
    public function givePermissionTo(...$permissions): static
    {
        return app(OrganizationContext::class)->outside(fn (): static => $this->spatieGivePermissionTo(...$permissions));
    }
}
