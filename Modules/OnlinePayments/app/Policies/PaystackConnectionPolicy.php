<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The organization's own Paystack connection (ONBOARDING_COMPLIANCE.md
 * § 4.2, ARCHITECTURE.md § 10), in the current organization. Seeing it
 * (masked keys, webhook address, status) needs `payment_settings.view`,
 * changing it `organization.payment_settings`: both org-owner only, like
 * the bank accounts (P01-05). Both permissions are dropped while GoProperty
 * staff impersonate a user, and both abilities are marked
 * #[BlockedWhileImpersonating] as well (AGENT.md § 3 rule 9: viewing the
 * keys too). In a suspended organization the owner can still look but not
 * change anything (OrganizationWriteGuard drops the managing permission).
 */
final class PaystackConnectionPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    #[BlockedWhileImpersonating]
    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewPaymentSettings);
    }

    /** Connect, replace the keys, test, rotate the webhook address, disconnect, set the online cap. */
    #[BlockedWhileImpersonating]
    public function manage(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManagePaymentSettings);
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
