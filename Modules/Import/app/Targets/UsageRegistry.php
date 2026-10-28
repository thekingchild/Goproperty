<?php

declare(strict_types=1);

namespace Modules\Import\Targets;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Database\Query\Builder;
use LogicException;
use Modules\Import\Contracts\ImportUsage;
use Modules\Import\Contracts\ImportUsages;

/** The registered ImportUsage answers (a singleton); classes are resolved when first asked. */
final class UsageRegistry implements ImportUsages
{
    /** @var list<class-string<ImportUsage>|ImportUsage> */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    public function register(string|ImportUsage $usage): void
    {
        $this->registered[] = $usage;
    }

    public function used(string $kind, Builder $ids, int $batchId): bool
    {
        foreach ($this->registered as $usage) {
            $usage = $usage instanceof ImportUsage ? $usage : $this->container->make($usage);

            if (! $usage instanceof ImportUsage) {
                throw new LogicException('Import usages implement '.ImportUsage::class.'.');
            }

            if ($usage->used($kind, $ids, $batchId)) {
                return true;
            }
        }

        return false;
    }
}
