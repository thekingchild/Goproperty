<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use Modules\Tenants\Contracts\TenantDetails;

/**
 * A tenant of the owner's buildings (P03-16; product owner 2026-10-29):
 * name, phone, email, address, occupation, emergency contact, KYC status,
 * ID type and the ID number masked to its last four characters
 * (TenantDetails never holds more), and the tenant's leases in the owner's
 * buildings at this organization.
 */
final readonly class OwnerTenantPage
{
    /** @param  list<OwnerLease>  $leases  newest first */
    public function __construct(
        public OwnerAgency $agency,
        public TenantDetails $tenant,
        public array $leases,
    ) {}
}
