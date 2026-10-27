<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

use App\Media\Media;
use Modules\Leasing\Contracts\TenantLeaseContracts;
use Modules\Leasing\Models\Lease;

/**
 * The Leasing module's answer to Contracts\TenantLeaseContracts (the tenant
 * portal, P03-11b): the lease is looked up through the organization scope
 * among the given tenants' activated leases only, then printed exactly as
 * the members' re-print does (LeaseContracts: the contract recorded at
 * issue, its PDF rendered once and kept as evidence).
 */
final readonly class TenantContractFiles implements TenantLeaseContracts
{
    public function __construct(private LeaseContracts $contracts) {}

    public function pdf(int $leaseId, array $tenantProfileIds): ?Media
    {
        if ($tenantProfileIds === []) {
            return null;
        }

        $lease = Lease::query()
            ->whereIn('tenant_profile_id', $tenantProfileIds)
            ->whereNotNull('contract_number')
            ->whereKey($leaseId)
            ->first();

        if (! $lease instanceof Lease) {
            return null;
        }

        return $this->contracts->pdf($this->contracts->forLease($lease) ?? $this->contracts->issue($lease));
    }
}
