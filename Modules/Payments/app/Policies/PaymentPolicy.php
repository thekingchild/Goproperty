<?php

declare(strict_types=1);

namespace Modules\Payments\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Payments\Models\Payment;
use Modules\Tenants\Contracts\TenantDirectory;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Payments in the current organization (ARCHITECTURE.md § 5):
 * `payments.view` (org-owner, org-admin, agent; property owners later in
 * their portal; kept while suspended) sees them and exports the list
 * (tenant and payer names and amounts, no contact details);
 * `payments.confirm` (org-owner, org-admin, agent) records, confirms and
 * rejects them, through Payments\PaymentService, which checks this policy
 * again. Tenants confirm nothing (§ 5: they only submit proof, P03-09).
 * Another organization's payments never reach a policy (the organization
 * scope hides them).
 *
 * The central guards apply on top: in a suspended organization only the
 * reads pass (OrganizationWriteGuard: `payments.confirm` is dropped, so
 * members record, confirm and reject nothing until it is reactivated;
 * payments the system records — a verified Paystack webhook, P03-08 —
 * still land, the money having already arrived); while GoProperty staff
 * impersonate, `payments.confirm` is dropped in both modes
 * (ImpersonationGuard, PLATFORM_CONSOLE.md § 6: money actions), and
 * create, confirm and reject are also marked #[BlockedWhileImpersonating].
 *
 * A payment is never deleted or edited (DATABASE_SCHEMA.md integrity rule
 * 3): `update` and `delete` always answer no; a pending payment that should
 * not count is rejected with a reason, a confirmed one will be reversed.
 *
 * Agent narrowing (decision 9): a payment follows its tenant, so an agent
 * assigned to buildings sees the payments of the tenants they see
 * (Modules\Tenants\Contracts\TenantDirectory::narrowingAllows, on
 * BuildingAccess); any other payment answers 404, checked before the
 * ability's own rule.
 */
final class PaymentPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantDirectory $tenants,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewPayments);
    }

    /** Export the payments list (P02-07; Lists\PaymentList, already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Payment $payment): Response|bool
    {
        return $this->reachable($user, $payment) ?? $this->viewAny($user);
    }

    /** Record a payment (a money action). */
    #[BlockedWhileImpersonating]
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ConfirmPayments);
    }

    /** Confirm a payment waiting for confirmation: allocate it to bills (a money action). */
    #[BlockedWhileImpersonating]
    public function confirm(User $user, Payment $payment): Response|bool
    {
        return $this->reachable($user, $payment)
            ?? ($payment->status->isPending() && $this->holds($user, OrganizationPermission::ConfirmPayments));
    }

    /** Reject a payment waiting for confirmation, with a reason (a money action). */
    #[BlockedWhileImpersonating]
    public function reject(User $user, Payment $payment): Response|bool
    {
        return $this->confirm($user, $payment);
    }

    /** Payments are history: never edited. */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /** Payments are history: never deleted (a confirmed one is reversed, later). */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * Null when the payment is this organization's and its tenant is one
     * the member sees (the ability's own rule decides); false for another
     * organization's payment; 404 outside a narrowed agent's tenants.
     */
    private function reachable(User $user, Payment $payment): Response|false|null
    {
        if (! $this->context->has() || $payment->organization_id !== $this->context->id()) {
            return false;
        }

        return $this->tenants->narrowingAllows($user, $payment->tenant_profile_id) ? null : Response::denyAsNotFound();
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
