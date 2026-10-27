<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Documents\Invoice\BankTransfer;

/**
 * How a tenant pays one of their bills, for the tenant portal's bill page
 * (Contracts\TenantBills, P03-11a): what the public pay page shows
 * (ONBOARDING_COMPLIANCE.md § 4.1; BRANDING_DOCUMENTS.md § 1 "Pay page"),
 * plus the lines. `bank` is the organization's primary usable account now
 * (P01-05), only while the bill asks for money (null otherwise, or when
 * the organization has none); `options` is the HTML of each online payment
 * option that offers the bill (Contracts\PaymentOptions, e.g. P03-08's
 * Paystack button), empty once paid or void; `payUrl` is the bill's public
 * pay page (/pay/{reference}).
 */
final readonly class TenantBillPayment
{
    /**
     * @param  list<array{label: string, note: ?string, period: ?string, amount: string}>  $lines
     * @param  list<string>  $options
     */
    public function __construct(
        public TenantBill $bill,
        public array $lines,
        public ?BankTransfer $bank,
        public array $options,
        public string $payUrl,
        public ?string $voidReason,
    ) {}
}
