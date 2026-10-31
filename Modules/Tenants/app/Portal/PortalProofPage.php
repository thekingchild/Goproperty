<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Billing\Contracts\TenantBill;

/**
 * The proof upload page of one of the user's bills (P03-11b): the agency,
 * the bill, and whether a proof may be sent for it now (Payments'
 * Contracts\PaymentProofs::maySubmit — the bill still asks for money and
 * GoProperty staff are not impersonating the user; it stays true while the
 * agency is suspended, ONBOARDING_COMPLIANCE.md § 3).
 */
final readonly class PortalProofPage
{
    public function __construct(
        public AgencyRentals $agency,
        public TenantBill $bill,
        public bool $maySubmit,
        /** The bill's part payments when its lease sets a limit (P03-17): the line to show, and what this proof must at least be (the last one allowed) or that none is left. */
        public ?string $partPayments = null,
    ) {}
}
