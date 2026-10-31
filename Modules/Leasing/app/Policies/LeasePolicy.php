<?php

declare(strict_types=1);

namespace Modules\Leasing\Policies;

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\BuildingAccess;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Leases in the current organization (ARCHITECTURE.md § 5): `leases.view`
 * (org-owner, org-admin, agent; kept while suspended) sees them;
 * `leases.manage` (the same three: agents sign leases for their
 * buildings) drafts, changes drafts, activates, renews, ends and settles
 * deposits (through Leases\LeaseService, which checks this policy again).
 * Property owners and tenants never open the workspace; another
 * organization's leases never reach a policy (the organization scope hides
 * them).
 *
 * A `restricted` organization makes no new leases: no drafts, renewals or
 * activations (ONBOARDING_COMPLIANCE.md § 5.5); it still ends leases and
 * changes drafts. The central guards apply on top: in a suspended
 * organization only the reads pass (OrganizationWriteGuard); while
 * GoProperty staff impersonate, a read-only session changes nothing and
 * settling a deposit (a money record) is refused in both modes
 * (ImpersonationGuard, PLATFORM_CONSOLE.md § 6).
 *
 * Agent narrowing (P02-03, decision 9): an agent assigned to buildings
 * sees and works on those buildings' leases only; any other lease answers
 * 404 (Response::denyAsNotFound), checked before the ability's own rule.
 */
final class LeasePolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewLeases);
    }

    /** Export the leases list (P02-07; Lists\LeaseList, already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? $this->viewAny($user);
    }

    /** Draft a new lease (which unit: LeaseService checks the member's buildings). */
    public function create(User $user): bool
    {
        return ! $this->restricted() && $this->manages($user);
    }

    /** Change a draft's terms. */
    public function update(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? ($lease->status === LeaseStatus::Draft && $this->manages($user));
    }

    public function activate(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? ($lease->status === LeaseStatus::Draft && ! $this->restricted() && $this->manages($user));
    }

    /** Draft the renewal of an active lease. */
    public function renew(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? ($lease->status === LeaseStatus::Active && ! $this->restricted() && $this->manages($user));
    }

    /** End an active lease early, or cancel a draft. */
    public function terminate(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease)
            ?? (in_array($lease->status, [LeaseStatus::Draft, LeaseStatus::Active], true) && $this->manages($user));
    }

    /**
     * How many payments a rent bill of this lease may be paid in (P03-17):
     * owners and administrators (`organization.update`, the same as the
     * organization's default on Settings → Rent payments), not agents, on a
     * lease that has not ended.
     */
    public function setPaymentLimit(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? (! $lease->status->isFinal() && $this->holds($user, OrganizationPermission::UpdateOrganization));
    }

    /** Record the deposit refunded or kept once the lease has ended (a money record). */
    #[BlockedWhileImpersonating]
    public function settleDeposit(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? ($lease->status->hasEnded() && $this->manages($user));
    }

    /**
     * Download the contract PDF (P03-03b) of a lease that has one (it was
     * activated). Whoever sees the lease may print it; staff impersonating
     * a member may not take a copy away, since it carries the tenant's
     * contact details (PLATFORM_CONSOLE.md § 6, as for list exports).
     */
    #[BlockedWhileImpersonating]
    public function downloadContract(User $user, Lease $lease): Response|bool
    {
        return $this->reachable($user, $lease) ?? ($lease->contract_number !== null && $this->viewAny($user));
    }

    /**
     * Null when the lease is this organization's and in the member's
     * buildings (the ability's own rule decides); false for another
     * organization's lease; 404 outside a narrowed agent's buildings.
     */
    private function reachable(User $user, Lease $lease): Response|false|null
    {
        if (! $this->context->has() || $lease->organization_id !== $this->context->id()) {
            return false;
        }

        return $this->access->allows($user, $lease->property_id) ? null : Response::denyAsNotFound();
    }

    private function manages(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ManageLeases);
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
