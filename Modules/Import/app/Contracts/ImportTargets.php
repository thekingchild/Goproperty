<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * The registry of import targets. A module adds its targets from its
 * service provider:
 *
 *     $this->callAfterResolving(ImportTargets::class, fn (ImportTargets $targets) => $targets->register(TenantsImportTarget::class));
 */
interface ImportTargets
{
    /** @param  class-string<ImportTarget>|ImportTarget  $target */
    public function register(string|ImportTarget $target): void;

    /** @return list<ImportTarget> in commit order */
    public function all(): array;

    public function find(string $key): ?ImportTarget;
}
