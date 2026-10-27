<?php

declare(strict_types=1);

namespace Modules\Properties\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Modules\Properties\Models\Property;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Buildings in the current organization (ARCHITECTURE.md § 5, § 6.2).
 * Workspace members holding `properties.view` (org-owner, org-admin, agent)
 * see them, archived ones too (read-only); `properties.manage` (the same
 * three) edits an active building and its photos; `properties.portfolio`
 * (org-owner, org-admin) adds, archives and restores buildings. Property
 * owners and tenants never open the workspace. Another organization's
 * buildings never reach a policy: the organization scope hides them.
 *
 * A `restricted` organization adds no buildings and restores none
 * (ONBOARDING_COMPLIANCE.md § 5.5); editing goes on. The central guards
 * apply on top: in a suspended organization only the reads pass
 * (OrganizationWriteGuard), and while GoProperty staff impersonate,
 * archiving (`delete`) and removing a photo (`deletePhoto`) are refused
 * (ImpersonationGuard). P02-03 narrows agents to their assigned buildings
 * here.
 */
final class PropertyPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewProperties);
    }

    public function view(User $user, Property $property): bool
    {
        return $this->belongsHere($property) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return ! $this->restricted() && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
    }

    /** Edit an active building's details. */
    public function update(User $user, Property $property): bool
    {
        return $this->belongsHere($property) && ! $property->isArchived()
            && $this->holds($user, OrganizationPermission::ManageProperties);
    }

    /** Add photos to an active building, or choose its cover. */
    public function addPhotos(User $user, Property $property): bool
    {
        return $this->update($user, $property);
    }

    /** Remove one of an active building's photos (the file is deleted). */
    public function deletePhoto(User $user, Property $property): bool
    {
        return $this->update($user, $property);
    }

    /** Archive (a status change, never a delete; AGENT.md § 3 rule 7). */
    public function delete(User $user, Property $property): bool
    {
        return $this->belongsHere($property) && ! $property->isArchived()
            && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
    }

    /** Bring an archived building back. */
    public function restore(User $user, Property $property): bool
    {
        return $this->belongsHere($property) && $property->isArchived() && ! $this->restricted()
            && $this->holds($user, OrganizationPermission::ManagePropertyPortfolio);
    }

    private function belongsHere(Property $property): bool
    {
        return $this->context->has() && $property->organization_id === $this->context->id() && ! $property->trashed();
    }

    private function restricted(): bool
    {
        return $this->context->has() && $this->context->get()->status === OrganizationStatus::Restricted;
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
