<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * What other modules may know about the leases an import batch brought in
 * (P03-13a, DATA_IMPORT.md § 2): which units they hold (the Units sheet's
 * rollback check ignores the batch's own leases, which are rolled back
 * first) and whose leases they are (the "Invite tenants now" step).
 * Leases a rollback archived do not count. Answers for the current
 * organization only. Bound in LeasingServiceProvider.
 */
interface ImportedLeases
{
    /** A sub-query selecting `unit_id` of the batch's draft and active leases, for `whereIn(…)`. */
    public function heldUnitIdsQuery(int $batchId): Builder;

    /** A sub-query selecting `tenant_profile_id` of the batch's leases (any status), for `whereIn(…)`. */
    public function batchTenantIdsQuery(int $batchId): Builder;
}
