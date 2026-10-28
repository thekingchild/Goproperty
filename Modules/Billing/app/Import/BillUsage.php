<?php

declare(strict_types=1);

namespace Modules\Billing\Import;

use Illuminate\Contracts\Database\Query\Builder;
use Modules\Billing\Models\Bill;
use Modules\Import\Contracts\ImportUsage;

/**
 * Billing's answer for an import's rollback check (DATA_IMPORT.md § 2
 * "Undo", P03-13a): an imported lease, tenant or unit is used once a bill
 * names it that the batch did not create, void bills included (a bill was
 * sent). A payment is always against a bill or a tenant, so Payments
 * answers for tenants.
 */
final class BillUsage implements ImportUsage
{
    public function used(string $kind, Builder $ids, int $batchId): bool
    {
        $column = match ($kind) {
            self::LEASE => 'lease_id',
            self::TENANT => 'tenant_profile_id',
            self::UNIT => 'unit_id',
            default => null,
        };

        return $column !== null && Bill::query()
            ->whereIn($column, $ids)
            ->where(static fn ($query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
            ->exists();
    }
}
