<?php

declare(strict_types=1);

namespace Modules\Handovers\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationType;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Modules\Handovers\Models\ManagementHandover;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Management handovers (BILLING_PAYMENTS.md § 7.7 rule 1, ARCHITECTURE.md
 * § 5): only an organization's owners act. Everything here needs
 * `handovers.manage` in the current organization, which only `org-owner`
 * holds (OrganizationRole), which is dropped while GoProperty staff
 * impersonate (handovers are always blocked then, PLATFORM_CONSOLE.md
 * § 6; the marks below say so again) and which a suspended organization
 * does not keep (they are writes; OrganizationPermission::
 * allowedWhileSuspended).
 *
 * The offering organization (the row's owner: the landlord's, or the
 * agency's for a one-way return, § 7.1) offers and withdraws; the
 * receiving organization (`to_organization_id`) accepts or declines. Either side gives notice; only the side that gave it withdraws
 * it. Both see the handover and settle the moving tenants' credit, each
 * its own part (Handovers\HandoverCredits).
 */
final class ManagementHandoverPolicy
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** The handovers page: this organization's offers, and those made to it. */
    public function viewAny(User $user): bool
    {
        return $this->holds($user);
    }

    /** Export the handovers made or received (P02-07, Lists\Handovers…List); always blocked while staff impersonate. */
    #[BlockedWhileImpersonating]
    public function export(User $user): bool
    {
        return $this->holds($user);
    }

    public function view(User $user, ManagementHandover $handover): bool
    {
        return ($this->isOffering($handover) || $this->isReceiving($handover)) && $this->holds($user);
    }

    /** Offer buildings to an agency: a landlord's organization (property owner, or both) only. */
    #[BlockedWhileImpersonating]
    public function create(User $user): bool
    {
        return $this->holds($user)
            && in_array($this->context->get()->type, [OrganizationType::PropertyOwner, OrganizationType::Hybrid], true);
    }

    /**
     * Return buildings held without a handover to the landlord's own
     * organization (§ 7.1, P04-04): an agency (or both) only. Which
     * buildings, and to whom, HandoverService checks.
     */
    #[BlockedWhileImpersonating]
    public function offerReturn(User $user): bool
    {
        return $this->holds($user)
            && in_array($this->context->get()->type, [OrganizationType::Agency, OrganizationType::Hybrid], true);
    }

    /** Withdraw an offer this organization made. */
    #[BlockedWhileImpersonating]
    public function cancel(User $user, ManagementHandover $handover): bool
    {
        return $this->isOffering($handover) && $this->holds($user);
    }

    /** Accept or decline an offer made to this organization. */
    #[BlockedWhileImpersonating]
    public function respond(User $user, ManagementHandover $handover): bool
    {
        return $this->isReceiving($handover) && $this->holds($user);
    }

    /** End the management with notice: either side. */
    #[BlockedWhileImpersonating]
    public function giveNotice(User $user, ManagementHandover $handover): bool
    {
        return ($this->isOffering($handover) || $this->isReceiving($handover)) && $this->holds($user);
    }

    /** Withdraw the notice this organization gave (HandoverService refuses the other side's). */
    #[BlockedWhileImpersonating]
    public function withdrawNotice(User $user, ManagementHandover $handover): bool
    {
        return ($this->isOffering($handover) || $this->isReceiving($handover)) && $this->holds($user);
    }

    /** Settle a moving tenant's credit (P03 decision 11): money, so owners only and never while staff impersonate. */
    #[BlockedWhileImpersonating]
    public function settleCredit(User $user, ManagementHandover $handover): bool
    {
        return ($this->isOffering($handover) || $this->isReceiving($handover)) && $this->holds($user);
    }

    private function isOffering(ManagementHandover $handover): bool
    {
        return $this->context->has() && $handover->organization_id === $this->context->id();
    }

    private function isReceiving(ManagementHandover $handover): bool
    {
        return $this->context->has() && $handover->to_organization_id === $this->context->id();
    }

    private function holds(User $user): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo(OrganizationPermission::ManageHandovers->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
