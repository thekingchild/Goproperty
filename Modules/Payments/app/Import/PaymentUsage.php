<?php

declare(strict_types=1);

namespace Modules\Payments\Import;

use Illuminate\Contracts\Database\Query\Builder;
use Modules\Import\Contracts\ImportUsage;
use Modules\Payments\Models\Payment;

/**
 * Payments' answer for an import's rollback check (DATA_IMPORT.md § 2
 * "Undo", P03-13a): an imported tenant is used once a payment from them is
 * recorded that the batch did not create, whatever its status (pending,
 * confirmed or rejected). Payments against an imported lease's bills make
 * the lease used through Billing's answer (the bill exists).
 */
final class PaymentUsage implements ImportUsage
{
    public function used(string $kind, Builder $ids, int $batchId): bool
    {
        return $kind === self::TENANT && Payment::query()
            ->whereIn('tenant_profile_id', $ids)
            ->where(static fn ($query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
            ->exists();
    }
}
