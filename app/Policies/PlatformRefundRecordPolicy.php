<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformRefundRecord;
use App\Platform\Staff\StaffPermission as P;

/**
 * Refunds on the console's payment and invoice pages (P06-07b): seen by
 * whoever sees the payments; **Check with Paystack** for a refund Paystack
 * is processing with `refunds.create` or `invoices.manage`. Refunds are
 * approved in Staff → Approvals (`refunds.approve`), never here.
 */
final class PlatformRefundRecordPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::InvoicesManage)
            || $actor->holdsStaffPermission(P::PaymentsConfirmTransfer)
            || $actor->holdsStaffPermission(P::RefundsCreate)
            || $actor->holdsStaffPermission(P::EmailsResend);
    }

    public function view(PlatformStaff $actor, PlatformRefundRecord $refund): bool
    {
        return $this->viewAny($actor);
    }

    public function check(PlatformStaff $actor, PlatformRefundRecord $refund): bool
    {
        return $refund->status === 'processing' && $refund->paystack_refund_id !== null
            && ($actor->holdsStaffPermission(P::RefundsCreate) || $actor->holdsStaffPermission(P::InvoicesManage));
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, PlatformRefundRecord $refund): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, PlatformRefundRecord $refund): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
