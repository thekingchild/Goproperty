<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

use App\Media\Media;
use Modules\Leasing\Contracts\OwnerLeaseContracts;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;

/**
 * The Leasing module's answer to Contracts\OwnerLeaseContracts (the owner
 * portal, P03-16): the lease is looked up through the organization scope
 * among the given buildings' activated leases only, and its contract must
 * already be on file here. The owner gets the PDF exactly as the members'
 * re-print and the tenant portal do (LeaseContracts::pdf(): the contract
 * recorded at issue, rendered once and kept as evidence); the owner's
 * portal never issues a contract itself.
 */
final readonly class OwnerContractFiles implements OwnerLeaseContracts
{
    public function __construct(private LeaseContracts $contracts) {}

    public function pdf(int $leaseId, array $propertyIds): ?Media
    {
        if ($propertyIds === []) {
            return null;
        }

        $lease = Lease::query()
            ->whereIn('property_id', $propertyIds)
            ->where('status', '!=', LeaseStatus::Draft)
            ->whereNotNull('activated_at')
            ->whereKey($leaseId)
            ->first();
        $contract = $lease instanceof Lease ? $this->contracts->forLease($lease) : null;

        return $contract instanceof LeaseContract ? $this->contracts->pdf($contract) : null;
    }
}
