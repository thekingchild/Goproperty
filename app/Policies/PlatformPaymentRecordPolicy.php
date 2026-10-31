<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformPaymentRecord;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Billing → Payments (P06-07b; docs/plans/P06-subtasks.md
 * defaults 56–57): seen with `invoices.manage`, `payments.confirm_transfer`
 * or `refunds.create` (finance-admin, super-admin); **Refund** a confirmed
 * or held payment with `refunds.create`. SaasBilling's PlatformRefunds
 * checks again, and above the configured amount the refund waits for a
 * second staff member with `refunds.approve`. Payments are never created,
 * edited or deleted here.
 */
final class PlatformPaymentRecordPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::InvoicesManage)
            || $actor->holdsStaffPermission(P::PaymentsConfirmTransfer)
            || $actor->holdsStaffPermission(P::RefundsCreate);
    }

    public function view(PlatformStaff $actor, PlatformPaymentRecord $payment): bool
    {
        return $this->viewAny($actor);
    }

    public function refund(PlatformStaff $actor, PlatformPaymentRecord $payment): bool
    {
        return $actor->holdsStaffPermission(P::RefundsCreate) && in_array($payment->status, ['confirmed', 'held'], true);
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, PlatformPaymentRecord $payment): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, PlatformPaymentRecord $payment): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
