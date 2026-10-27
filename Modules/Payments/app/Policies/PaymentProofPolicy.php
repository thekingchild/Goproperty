<?php

declare(strict_types=1);

namespace Modules\Payments\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Models\PaymentProof;
use Modules\Tenants\Contracts\TenantDirectory;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Proofs of payment (P03-09; ARCHITECTURE.md § 5, ONBOARDING_COMPLIANCE.md
 * § 4.1):
 *
 * - Tenants submit proofs only for their own bills (submitAsTenant): the
 *   bill's tenant record must be linked to the user's portal account
 *   (`tenant_profiles.user_id`, P03-01b) and not archived; anyone else's
 *   bill, or another organization's, answers 404. Tenants hold no
 *   workspace role (P03 decision 4), so their portal identity is the
 *   permission. Kept while the organization is suspended
 *   (#[AllowedWhileSuspended]: "the tenant portal stays read-only and still
 *   accepts payment proofs", ONBOARDING_COMPLIANCE.md § 3).
 * - Members: `payments.view` sees proofs and opens their files (narrowed
 *   like payments: a proof follows its tenant); `payments.confirm` (record,
 *   confirm and reject) uploads a proof for a tenant (create) and archives
 *   one. Reviewing is confirming or rejecting the proof's payment
 *   (PaymentPolicy).
 * - Evidence (AGENT.md § 3 rule 7): update and delete always answer no;
 *   "remove" is archive.
 *
 * While GoProperty staff impersonate, every submission, archive and the
 * file itself are refused (#[BlockedWhileImpersonating]: money actions; a
 * transfer screenshot shows the payer's bank details), in both modes; the
 * proof's typed details stay visible on the payment page.
 */
final class PaymentProofPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantDirectory $tenants,
        private readonly BillLedger $ledger,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ViewPayments);
    }

    public function view(User $user, PaymentProof $proof): Response|bool
    {
        return $this->reachable($user, $proof) ?? $this->viewAny($user);
    }

    /** Open the file (through a short-lived signed link): it may show the payer's bank details. */
    #[BlockedWhileImpersonating]
    public function viewFile(User $user, PaymentProof $proof): Response|bool
    {
        return $this->view($user, $proof);
    }

    /** A member uploads a proof the tenant sent them (records a payment: a money action). */
    #[BlockedWhileImpersonating]
    public function create(User $user): bool
    {
        return $this->holds($user, OrganizationPermission::ConfirmPayments);
    }

    /**
     * The tenant submits a proof for one of their own bills, from the
     * portal: the bill is in this organization, issued to the tenant record
     * linked to the user, still asks for money.
     */
    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function submitAsTenant(User $user, int $billId): Response|bool
    {
        if (! $this->context->has()) {
            return false;
        }

        $bill = $this->ledger->balance($billId);
        $tenant = $bill !== null ? $this->tenants->find($bill->tenantProfileId) : null;

        if ($bill === null || $tenant === null || $tenant->archived || $tenant->userId === null || $tenant->userId !== $user->id) {
            return Response::denyAsNotFound();
        }

        if ($bill->isVoid()) {
            return Response::deny(__('Bill :number is void: do not pay it.', ['number' => $bill->number]));
        }

        if (! $bill->outstanding->isPositive()) {
            return Response::deny(__('Bill :number is paid already.', ['number' => $bill->number]));
        }

        return true;
    }

    /** "Remove" a proof: archived with a reason, the file kept. */
    #[BlockedWhileImpersonating]
    public function archive(User $user, PaymentProof $proof): Response|bool
    {
        return $this->reachable($user, $proof)
            ?? (! $proof->isArchived() && $this->holds($user, OrganizationPermission::ConfirmPayments));
    }

    /** What was submitted never changes. */
    public function update(User $user, PaymentProof $proof): bool
    {
        return false;
    }

    /** Evidence: never deleted (archive instead). */
    public function delete(User $user, PaymentProof $proof): bool
    {
        return false;
    }

    /** Evidence: never deleted (only the NDPA erasure tooling purges). */
    public function forceDelete(User $user, PaymentProof $proof): bool
    {
        return false;
    }

    /**
     * Null when the proof is this organization's and its tenant is one the
     * member sees; false for another organization's; 404 outside a narrowed
     * agent's tenants.
     */
    private function reachable(User $user, PaymentProof $proof): Response|false|null
    {
        if (! $this->context->has() || $proof->organization_id !== $this->context->id()) {
            return false;
        }

        return $this->tenants->narrowingAllows($user, $proof->tenant_profile_id) ? null : Response::denyAsNotFound();
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
