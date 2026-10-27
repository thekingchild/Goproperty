<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationPermission;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The organization's bank accounts (ONBOARDING_COMPLIANCE.md § 4.1), in the
 * current organization. Seeing them needs `payment_settings.view`,
 * changing them `organization.payment_settings`; both are org-owner only
 * (where tenants' money goes is the owner's call, as for Paystack keys in
 * § 4.2), and both are dropped while GoProperty staff impersonate the user,
 * so support never sees or changes them (ImpersonationGuard). In a
 * suspended organization the owner can still see them but not change them
 * (OrganizationWriteGuard). Another organization's accounts never reach a
 * policy: the organization scope hides them.
 */
final class OrganizationBankAccountPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewPaymentSettings);
    }

    public function view(User $user, OrganizationBankAccount $account): bool
    {
        return $this->belongsHere($account) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManagePaymentSettings);
    }

    /** Edit, check the name, make primary, deactivate, reactivate. */
    public function update(User $user, OrganizationBankAccount $account): bool
    {
        return $this->belongsHere($account) && ! $account->trashed() && $this->create($user);
    }

    /** Remove (soft delete). */
    public function delete(User $user, OrganizationBankAccount $account): bool
    {
        return $this->update($user, $account);
    }

    private function belongsHere(OrganizationBankAccount $account): bool
    {
        return $this->context->has() && $account->organization_id === $this->context->id();
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
