<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use Illuminate\Contracts\Support\Htmlable;

/**
 * A way to pay a bill online, offered on the bill's pay page and printed
 * on its invoice as "Pay online" (BRANDING_DOCUMENTS.md § 1 "Pay page",
 * § 2 item 6; ONBOARDING_COMPLIANCE.md § 4.2): e.g. the agency's own
 * Paystack account (OnlinePayments, P03-08). Registered into
 * PaymentOptions from the module's provider; asked inside the bill's
 * organization. Bank transfer is always offered by the pay page itself.
 */
interface PaymentOption
{
    /** Whether the bill can be paid this way now (e.g. Paystack connected and the amount within the online limit). */
    public function offers(PayableBill $bill): bool;

    /** The option's part of the pay page (a button, a short form); only asked when offers() is true. */
    public function render(PayableBill $bill): Htmlable;
}
