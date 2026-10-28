<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * The registered ImportUsage answers (a singleton). A module adds its own
 * from its service provider:
 *
 *     $this->callAfterResolving(ImportUsages::class, fn (ImportUsages $usages) => $usages->register(BillUsage::class));
 */
interface ImportUsages
{
    /** @param  class-string<ImportUsage>|ImportUsage  $usage */
    public function register(string|ImportUsage $usage): void;

    /** Whether any registered module says one of these records is used (see ImportUsage::used()). */
    public function used(string $kind, Builder $ids, int $batchId): bool;
}
