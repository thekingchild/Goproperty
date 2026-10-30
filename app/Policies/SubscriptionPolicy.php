<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Settings → Subscription (P06-01b), in the current organization: seeing
 * the plan, its terms, a scheduled change and what the plan includes needs
 * `organization.billing` (owners) or `sms_credits.view` (owners and
 * administrators: they see the plan's allowances already). Changing plan
 * is paying GoProperty's way: `organization.billing`, never while GoProperty
 * staff impersonate, and refused while the organization is read-only
 * (OrganizationWriteGuard: only paying is allowed then).
 */
final class SubscriptionPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageBilling) || $this->holds($user, OrganizationPermission::ViewSmsCredits);
    }

    #[BlockedWhileImpersonating]
    public function changePlan(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageBilling);
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
