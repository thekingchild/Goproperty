<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Media\Media;

/**
 * How the owner portal (P03-16, the Properties module's /owner pages) opens
 * the issued contract of a lease of the owner's units: never the Leasing
 * module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Takes the building ids the portal proved belong to the signed-in owner
 * in the current organization and answers only for activated leases in
 * them whose contract this organization issued (`lease_contracts`): any
 * other lease, a draft, or a lease with no contract on file here (one a
 * handover brought in keeps its contract with the organization that issued
 * it) is not found (null). It never issues a contract: the PDF is the one
 * kept at issue, rendered now, once, only when the `pdf` queue has not got
 * to it yet (as every re-print). No permission check: the caller checks its
 * policy first. Bound in LeasingServiceProvider.
 */
interface OwnerLeaseContracts
{
    /** @param  list<int>  $propertyIds */
    public function pdf(int $leaseId, array $propertyIds): ?Media;
}
