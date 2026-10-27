<?php

declare(strict_types=1);

namespace Modules\Payments\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Receipts\ReceiptVoids;
use Modules\Tenants\Contracts\TenantDirectory;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Receipts in the current organization (P03-10b; ARCHITECTURE.md § 5
 * "Receipts"):
 *
 * - `payments.view` (org-owner, org-admin, agent; kept while suspended)
 *   sees receipts and exports the list (tenant names and amounts, no
 *   contact details: allowed while staff impersonate, as payments);
 * - viewDocument: the receipt's web page and PDFs, which print the
 *   tenant's phone and email: blocked while staff impersonate (as the
 *   lease contract and invoice), a read otherwise (works while suspended);
 * - voidRequest: `payments.confirm` (owners, administrators, agents) asks
 *   for a void with a reason;
 * - voidApprove: `receipts.approve_void` (owners, administrators), and
 *   never the member who asked (P03 decision 4: a second approver);
 * - voidAlone: the organization's only `receipts.approve_void` holder
 *   voids alone (decision 4's single-approver path);
 * - voidDismiss: the requester withdraws, or an approver declines.
 *
 * Every void ability starts with `void`, so the central guards refuse it
 * while staff impersonate (PLATFORM_CONSOLE.md § 6: "voiding receipts") and
 * in a suspended organization; they are also marked
 * #[BlockedWhileImpersonating]. Receipts are history: no update or delete.
 * A receipt follows its tenant, as payments do (decision 9): an agent
 * assigned to buildings sees the receipts of the tenants they see, any
 * other answers 404.
 */
final class ReceiptPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantDirectory $tenants,
        private readonly ReceiptVoids $voids,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewPayments);
    }

    /** Export the receipts list (P02-07; Lists\ReceiptList, already narrowed; no contact details). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Receipt $receipt): Response|bool
    {
        return $this->reachable($user, $receipt) ?? $this->viewAny($user);
    }

    /** The receipt as printed (web page, PDF, VOID copy): it shows the tenant's contact details. */
    #[BlockedWhileImpersonating]
    public function viewDocument(User $user, Receipt $receipt): Response|bool
    {
        return $this->view($user, $receipt);
    }

    #[BlockedWhileImpersonating]
    public function voidRequest(User $user, Receipt $receipt): Response|bool
    {
        return $this->reachable($user, $receipt)
            ?? (! $receipt->isVoided() && ! $receipt->voidPending() && $this->holds($user, OrganizationPermission::ConfirmPayments));
    }

    #[BlockedWhileImpersonating]
    public function voidApprove(User $user, Receipt $receipt): Response|bool
    {
        $reachable = $this->reachable($user, $receipt);

        if ($reachable !== null) {
            return $reachable;
        }

        if (! $receipt->voidPending() || ! $this->holds($user, OrganizationPermission::ApproveReceiptVoids)) {
            return false;
        }

        return $receipt->void_requested_by_user_id === $user->id
            ? Response::deny(__('You asked for this void: another owner or administrator must approve it.'))
            : true;
    }

    #[BlockedWhileImpersonating]
    public function voidAlone(User $user, Receipt $receipt): Response|bool
    {
        return $this->reachable($user, $receipt)
            ?? (! $receipt->isVoided()
                && (! $receipt->voidPending() || $receipt->void_requested_by_user_id === $user->id)
                && $this->holds($user, OrganizationPermission::ApproveReceiptVoids)
                && $this->voids->isSoleApprover($user));
    }

    #[BlockedWhileImpersonating]
    public function voidDismiss(User $user, Receipt $receipt): Response|bool
    {
        return $this->reachable($user, $receipt)
            ?? ($receipt->voidPending() && (
                ($receipt->void_requested_by_user_id === $user->id && $this->holds($user, OrganizationPermission::ConfirmPayments))
                || $this->holds($user, OrganizationPermission::ApproveReceiptVoids)
            ));
    }

    /** Receipts are history: never edited. */
    public function update(User $user, Receipt $receipt): bool
    {
        return false;
    }

    /** Receipts are history: never deleted (a wrong one is voided). */
    public function delete(User $user, Receipt $receipt): bool
    {
        return false;
    }

    /**
     * Null when the receipt is this organization's and its tenant is one
     * the member sees; false for another organization's receipt; 404
     * outside a narrowed agent's tenants.
     */
    private function reachable(User $user, Receipt $receipt): Response|false|null
    {
        if (! $this->context->has() || $receipt->organization_id !== $this->context->id()) {
            return false;
        }

        return $this->tenants->narrowingAllows($user, $receipt->tenant_profile_id) ? null : Response::denyAsNotFound();
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
