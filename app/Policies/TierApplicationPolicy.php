<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\TierApplication;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The org-owner's Transaction Tier application, in the current organization
 * (ONBOARDING_COMPLIANCE.md § 5.3): seeing it needs `compliance.view`,
 * changing it `organization.compliance`; both are `org-owner` only. In a
 * suspended organization the owner can still see it (a read) but not
 * change it (OrganizationWriteGuard denies every other ability). Another
 * organization's application never reaches a policy: the organization
 * scope hides it.
 */
final class TierApplicationPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewCompliance);
    }

    public function view(User $user, TierApplication $application): bool
    {
        return $this->belongsHere($application) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageCompliance);
    }

    /** Upload or remove documents, name the contact, change the tier, submit. */
    public function update(User $user, TierApplication $application): bool
    {
        return $this->belongsHere($application)
            && $application->status->isWithApplicant()
            && $this->holds($user, OrganizationPermission::ManageCompliance);
    }

    public function delete(User $user, TierApplication $application): bool
    {
        return false;
    }

    private function belongsHere(TierApplication $application): bool
    {
        return $this->context->has() && $application->organization_id === $this->context->id();
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
