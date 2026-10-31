<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\PlatformStaff;

/**
 * Sends a subscription invoice's email again (P06-07b; PLATFORM_CONSOLE.md
 * § 1: support resends invoices; docs/plans/P06-subtasks.md default 55):
 * the same email as when it was issued, to the owners of the organization
 * and of its payer, at most 3 times an hour per invoice, audited. Needs
 * `emails.resend` or `invoices.manage`, checked here again. Returns how
 * many owners it went to.
 */
interface InvoiceEmails
{
    /** @throws InvoiceEmailRefused when it cannot be sent now (the message says why) */
    public function resend(PlatformStaff $staff, int $invoiceId): int;
}
