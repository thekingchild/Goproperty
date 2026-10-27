<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Payments\Contracts\TenantReceipt;

/**
 * A receipt's page in the tenant portal (P03-11b): the agency (with the
 * user's bills there, to link the bills it paid), the receipt as issued,
 * and whether it may be opened as printed and downloaded now
 * (TenantProfilePolicy::downloadAsTenant: not while staff impersonate).
 */
final readonly class PortalReceiptPage
{
    public function __construct(
        public AgencyRentals $agency,
        public TenantReceipt $receipt,
        public bool $files,
    ) {}
}
