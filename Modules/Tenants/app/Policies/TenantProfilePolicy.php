<?php

declare(strict_types=1);

namespace Modules\Tenants\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Modules\Tenants\Models\TenantProfile;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Tenants' records in the current organization (ARCHITECTURE.md § 5).
 * Workspace members holding `tenants.view` (org-owner, org-admin, agent)
 * see them, ID numbers masked; `tenants.manage` (the same three: an agent
 * signing a lease adds its tenant) adds, edits, archives and restores.
 * Property owners and tenants never open the workspace and hold neither.
 * Another organization's tenants never reach a policy: the organization
 * scope hides them. P02-06b builds no tenant pages (P03-01a does); imports
 * write tenants under `imports.manage` (ImportBatchPolicy), not these.
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard), and while GoProperty staff
 * impersonate, archiving is refused (`delete`, ImpersonationGuard).
 */
final class TenantProfilePolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewTenants);
    }

    public function view(User $user, TenantProfile $tenant): bool
    {
        return $this->belongsHere($tenant) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageTenants);
    }

    public function update(User $user, TenantProfile $tenant): bool
    {
        return $this->belongsHere($tenant) && ! $tenant->trashed() && $this->create($user);
    }

    /** Archive (soft delete; AGENT.md § 3 rule 7). */
    public function delete(User $user, TenantProfile $tenant): bool
    {
        return $this->update($user, $tenant);
    }

    /** Bring an archived tenant back. */
    public function restore(User $user, TenantProfile $tenant): bool
    {
        return $this->belongsHere($tenant) && $tenant->trashed() && $this->create($user);
    }

    private function belongsHere(TenantProfile $tenant): bool
    {
        return $this->context->has() && $tenant->organization_id === $this->context->id();
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
