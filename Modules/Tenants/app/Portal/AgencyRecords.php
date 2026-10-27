<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use Modules\Payments\Contracts\TenantProof;
use Modules\Payments\Contracts\TenantReceipt;

/**
 * One agency's payments and documents in the tenant portal (P03-11b;
 * Portal\PortalPayments, Portal\PortalDocuments): the agency's frame with
 * the user's leases and bills there, the proofs they sent (or a member
 * uploaded for them) and the receipts issued to them, and whether files
 * may be opened now (TenantProfilePolicy::downloadAsTenant: not while
 * GoProperty staff impersonate). Plain values, read inside the agency's
 * organization and safe to render outside it.
 */
final readonly class AgencyRecords
{
    /**
     * @param  list<TenantProof>  $proofs  newest first
     * @param  list<TenantReceipt>  $receipts  newest first
     */
    public function __construct(
        public AgencyRentals $agency,
        public array $proofs,
        public array $receipts,
        public bool $files,
    ) {}

    /**
     * The leases that have a contract (activated), newest start first.
     *
     * @return list<PortalLease>
     */
    public function contracts(): array
    {
        return array_values(array_filter($this->agency->leases, static fn (PortalLease $lease): bool => $lease->lease->contractNumber !== null));
    }

    public function isEmpty(): bool
    {
        return $this->proofs === [] && $this->receipts === [] && $this->agency->bills === [] && $this->contracts() === [];
    }
}
