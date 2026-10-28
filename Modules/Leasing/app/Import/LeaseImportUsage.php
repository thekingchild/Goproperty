<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

use Illuminate\Contracts\Database\Query\Builder;
use Modules\Import\Contracts\ImportUsage;
use Modules\Leasing\Models\Lease;

/**
 * Leasing's answer for an import's rollback check (P03-13a): an imported
 * unit or tenant is used once a lease the batch did not bring in names it,
 * whatever that lease's status (a past lease still points at them).
 * Leases a rollback archived do not count.
 */
final class LeaseImportUsage implements ImportUsage
{
    public function used(string $kind, Builder $ids, int $batchId): bool
    {
        $column = match ($kind) {
            self::UNIT => 'unit_id',
            self::TENANT => 'tenant_profile_id',
            default => null,
        };

        return $column !== null && Lease::query()
            ->whereIn($column, $ids)
            ->where(static fn ($query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
            ->exists();
    }
}
