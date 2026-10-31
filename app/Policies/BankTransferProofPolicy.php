<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Billing\BankTransferProof;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Billing → Bank transfers (P06-04; BILLING_PAYMENTS.md § 5,
 * PLATFORM_CONSOLE.md § 1 "finance-admin … confirm bank-transfer
 * subscription payments"): seen, with the proof's file, with
 * `payments.confirm_transfer` or `invoices.manage`; **Confirm** and
 * **Reject** a proof still waiting with `payments.confirm_transfer` only
 * (finance-admin, super-admin). Nothing else changes a proof.
 */
final class BankTransferProofPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::PaymentsConfirmTransfer) || $actor->holdsStaffPermission(P::InvoicesManage);
    }

    public function view(PlatformStaff $actor, BankTransferProof $proof): bool
    {
        return $this->viewAny($actor);
    }

    public function review(PlatformStaff $actor, BankTransferProof $proof): bool
    {
        return $proof->isPending() && $actor->holdsStaffPermission(P::PaymentsConfirmTransfer);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, BankTransferProof $proof): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, BankTransferProof $proof): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
