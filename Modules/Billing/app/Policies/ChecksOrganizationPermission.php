<?php

declare(strict_types=1);

namespace Modules\Billing\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Whether a member holds a customer permission in the current
 * organization (the utility policies, P03-06a): false outside an
 * organization, for someone who is not a workspace member there, and for a
 * permission not seeded yet.
 *
 * @property-read OrganizationContext $context
 */
trait ChecksOrganizationPermission
{
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
