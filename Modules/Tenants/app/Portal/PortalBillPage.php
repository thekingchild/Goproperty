<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Billing\Contracts\TenantBillPayment;

/**
 * A bill's page in the tenant portal (P03-11a): the agency it came from,
 * the bill with how to pay it, and whether the invoice may be opened now
 * (TenantProfilePolicy::viewInvoiceAsTenant: not while staff impersonate).
 */
final readonly class PortalBillPage
{
    public function __construct(
        public AgencyRentals $agency,
        public TenantBillPayment $payment,
        public bool $invoice,
    ) {}
}
