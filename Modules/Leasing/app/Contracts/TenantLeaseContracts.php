<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use App\Media\Media;

/**
 * How the tenant portal (P03-11b, the Tenants module's /portal pages) opens
 * a tenant's own lease contract as printed (P03-03b): never the Leasing
 * module's models or tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Takes the tenant profile ids the portal proved are the signed-in user's
 * in the current organization and answers only for their leases that were
 * activated (they have a contract number): any other lease id — another
 * tenant's, another organization's, a draft — is not found (null).
 * Answers for the current organization only. No permission check: the
 * caller checks its policy first. Bound in LeasingServiceProvider.
 */
interface TenantLeaseContracts
{
    /**
     * The lease's contract PDF as kept (evidence): rendered once from what
     * was recorded at issue and never again; issued and rendered now, once,
     * when the lease's contract or its PDF is not there yet (as the
     * members' re-print does). Null for anyone else's lease or a draft.
     *
     * @param  list<int>  $tenantProfileIds
     */
    public function pdf(int $leaseId, array $tenantProfileIds): ?Media;
}
