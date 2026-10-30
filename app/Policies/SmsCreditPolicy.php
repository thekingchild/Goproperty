<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Settings → SMS credits (P05-03), in the current organization: seeing the
 * balance and the ledger, and exporting the ledger, need `sms_credits.view`
 * (owners, administrators; kept while suspended and while GoProperty staff
 * impersonate: no contact details, numbers are masked). Buying a bundle is
 * paying GoProperty: `organization.billing` (owners), dropped while staff
 * impersonate (ImpersonationGuard). While the organization is suspended the
 * page is read-only like every other (OrganizationWriteGuard): only the
 * subscription may be paid then, so a bundle waits for reactivation.
 */
final class SmsCreditPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewSmsCredits);
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function buy(User $user): bool
    {
        return $this->viewAny($user) && $this->holds($user, OrganizationPermission::ManageBilling);
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
