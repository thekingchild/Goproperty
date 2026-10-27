<?php

declare(strict_types=1);

namespace Modules\Tenants\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Tenants\Access\TenantNarrowing;
use Modules\Tenants\Models\TenantProfile;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Tenants' records in the current organization (ARCHITECTURE.md § 5).
 * Workspace members holding `tenants.view` (org-owner, org-admin, agent)
 * see them, archived ones too, ID numbers masked; `tenants.manage` (the
 * same three: an agent signing a lease adds its tenant) adds, edits,
 * archives and restores (through Profiles\TenantProfiles). Property owners
 * and tenants never open the workspace and hold neither. Another
 * organization's tenants never reach a policy: the organization scope
 * hides them. Imports write tenants under `imports.manage`
 * (ImportBatchPolicy), not these.
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard), and while GoProperty staff
 * impersonate, archiving is refused (`delete`, ImpersonationGuard) and so
 * is exporting the list (`export`: people's contact details).
 *
 * Agent narrowing (P02-03, decision 9): through Access\TenantNarrowing on
 * Modules\Properties\Contracts\BuildingAccess. Tenants have no building
 * until leases exist (P03-03a), so a narrowed agent sees every tenant for
 * now; a tenant outside the agent's buildings will answer 404
 * (Response::denyAsNotFound), checked before the ability's own rule.
 */
final class TenantProfilePolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantNarrowing $narrowing,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewTenants);
    }

    /**
     * Export the tenants list (P02-07; Lists\TenantList, already narrowed).
     * It holds people's contact details, so GoProperty staff may look at
     * the list while impersonating but never take a copy away
     * (PLATFORM_CONSOLE.md § 6).
     */
    #[BlockedWhileImpersonating]
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, TenantProfile $tenant): Response|bool
    {
        if (! $this->belongsHere($tenant)) {
            return false;
        }

        if (! $this->narrowing->allows($user, $tenant)) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageTenants);
    }

    public function update(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: false);
    }

    /** Archive (soft delete; AGENT.md § 3 rule 7). */
    public function delete(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: false);
    }

    /** Bring an archived tenant back. */
    public function restore(User $user, TenantProfile $tenant): Response|bool
    {
        return $this->managed($user, $tenant, archived: true);
    }

    private function managed(User $user, TenantProfile $tenant, bool $archived): Response|bool
    {
        if (! $this->belongsHere($tenant)) {
            return false;
        }

        if (! $this->narrowing->allows($user, $tenant)) {
            return Response::denyAsNotFound();
        }

        return $tenant->trashed() === $archived && $this->create($user);
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
