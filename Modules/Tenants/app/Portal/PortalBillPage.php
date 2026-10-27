<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Billing\Contracts\TenantBillPayment;
use Modules\Payments\Contracts\TenantProof;

/**
 * A bill's page in the tenant portal (P03-11a, P03-11b): the agency it came
 * from, the bill with how to pay it, whether the invoice may be opened now
 * (TenantProfilePolicy::viewInvoiceAsTenant) and its PDF and the proof
 * files (downloadAsTenant; neither while staff impersonate), the proofs
 * sent for this bill and where each stands, and whether a proof may be
 * sent now (Payments' Contracts\PaymentProofs::maySubmit: the bill still
 * asks for money, not in a support session — also while the agency is
 * suspended).
 */
final readonly class PortalBillPage
{
    /** @param  list<TenantProof>  $proofs  newest first */
    public function __construct(
        public AgencyRentals $agency,
        public TenantBillPayment $payment,
        public bool $invoice,
        public array $proofs = [],
        public bool $mayUploadProof = false,
        public bool $files = false,
    ) {}
}
