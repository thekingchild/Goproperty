<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlatformStaff;
use App\Platform\Billing\PlatformInvoiceRecord;
use App\Platform\Staff\StaffPermission as P;

/**
 * Console → Billing → Invoices (P06-07b; PLATFORM_CONSOLE.md § 1:
 * finance-admin "platform invoices", support-agent "resend emails/invoices";
 * docs/plans/P06-subtasks.md default 55): seen with `invoices.manage` or
 * `emails.resend`; **Send invoice email again** with either, for a
 * subscription invoice that is not void. Invoices are never created,
 * edited or deleted here.
 */
final class PlatformInvoiceRecordPolicy
{
    public function viewAny(PlatformStaff $actor): bool
    {
        return $actor->holdsStaffPermission(P::InvoicesManage) || $actor->holdsStaffPermission(P::EmailsResend);
    }

    public function view(PlatformStaff $actor, PlatformInvoiceRecord $invoice): bool
    {
        return $this->viewAny($actor);
    }

    public function resend(PlatformStaff $actor, PlatformInvoiceRecord $invoice): bool
    {
        return $this->viewAny($actor) && $invoice->kind === 'subscription' && $invoice->status !== 'void';
    }

    public function create(PlatformStaff $actor): bool
    {
        return false;
    }

    public function update(PlatformStaff $actor, PlatformInvoiceRecord $invoice): bool
    {
        return false;
    }

    public function delete(PlatformStaff $actor, PlatformInvoiceRecord $invoice): bool
    {
        return false;
    }

    public function deleteAny(PlatformStaff $actor): bool
    {
        return false;
    }
}
