<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Whether records an import created are used by something that is not the
 * import's own (DATA_IMPORT.md § 2 "Undo": a batch can be rolled back only
 * while none of its records has been used since). A module whose records
 * point at other modules' records answers for them: Leasing for units and
 * tenants a lease names, Billing for leases, tenants and units a bill
 * names, Payments for tenants a payment names (P03-13a). A target's
 * usedSince() asks every registered answer through ImportUsages, so it
 * never reads another module's tables (ARCHITECTURE.md § 8 rule 3).
 *
 * Records the same batch created (its `import_batch_id`) do not count: a
 * batch that imports units and their leases together can still be rolled
 * back, leases first.
 */
interface ImportUsage
{
    /** Units (`units.id`). */
    public const string UNIT = 'unit';

    /** Tenants (`tenant_profiles.id`). */
    public const string TENANT = 'tenant';

    /** Leases (`leases.id`). */
    public const string LEASE = 'lease';

    /**
     * Whether any record of this kind whose id the sub-query selects is used
     * by a record of this module that the batch did not create. Answers for
     * the current organization; false for a kind it knows nothing about.
     */
    public function used(string $kind, Builder $ids, int $batchId): bool;
}
