<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Billing transfers (BILLING_PAYMENTS.md § 7 rule 1, ARCHITECTURE.md § 5):
 * only an organization's owners act on its bill. Everything here needs
 * `organization.billing` in the current organization, which only
 * `org-owner` holds (OrganizationRole) and which is dropped while GoProperty
 * staff impersonate (billing transfers are always blocked then,
 * PLATFORM_CONSOLE.md § 6; the marks below say so again).
 *
 * The offering organization (the row's owner) sees its offers and
 * withdraws them; the receiving organization (`to_organization_id`) sees
 * the offers made to it and accepts or declines them. Neither side may do
 * the other's step. Writes are not reads, so a suspended organization's
 * owners are refused by the central guard (OrganizationWriteGuard).
 */
final class BillingTransferRequestPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** The billing transfers page: this organization's offers, and those made to it. */
    public function viewAny(User $user): bool
    {
        return $this->holdsBilling($user);
    }

    public function view(User $user, BillingTransferRequest $transfer): bool
    {
        return ($this->isOffering($transfer) || $this->isReceiving($transfer)) && $this->holdsBilling($user);
    }

    /** Offer this organization's bill to another organization. */
    #[BlockedWhileImpersonating]
    public function create(User $user): bool
    {
        return $this->holdsBilling($user);
    }

    /** Withdraw an offer this organization made. */
    #[BlockedWhileImpersonating]
    public function cancel(User $user, BillingTransferRequest $transfer): bool
    {
        return $this->isOffering($transfer) && $this->holdsBilling($user);
    }

    /** Accept or decline an offer made to this organization. */
    #[BlockedWhileImpersonating]
    public function respond(User $user, BillingTransferRequest $transfer): bool
    {
        return $this->isReceiving($transfer) && $this->holdsBilling($user);
    }

    private function isOffering(BillingTransferRequest $transfer): bool
    {
        return $this->context->has() && $transfer->organization_id === $this->context->id();
    }

    private function isReceiving(BillingTransferRequest $transfer): bool
    {
        return $this->context->has() && $transfer->to_organization_id === $this->context->id();
    }

    private function holdsBilling(User $user): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo(OrganizationPermission::ManageBilling->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
