<?php

declare(strict_types=1);

namespace Modules\Import\Targets;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportTargets;

/**
 * The registered import targets (a singleton). Modules register theirs
 * from their service providers; a key used twice is a mistake and throws.
 * Classes are resolved from the container when first needed.
 */
final class TargetRegistry implements ImportTargets
{
    /** @var array<int, class-string<ImportTarget>|ImportTarget> */
    private array $registered = [];

    /** @var array<string, ImportTarget>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container) {}

    public function register(string|ImportTarget $target): void
    {
        $this->registered[] = $target;
        $this->resolved = null;
    }

    public function all(): array
    {
        $targets = array_values($this->resolve());
        usort($targets, static fn (ImportTarget $a, ImportTarget $b): int => [$a->order(), $a->key()] <=> [$b->order(), $b->key()]);

        return $targets;
    }

    public function find(string $key): ?ImportTarget
    {
        return $this->resolve()[$key] ?? null;
    }

    /** @return array<string, ImportTarget> */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->registered as $target) {
            $target = $target instanceof ImportTarget ? $target : $this->container->make($target);

            if (! $target instanceof ImportTarget) {
                throw new LogicException('Import targets implement '.ImportTarget::class.'.');
            }

            if (isset($resolved[$target->key()]) || $target->key() === 'workbook' || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $target->key()) !== 1) {
                throw new LogicException("Import target key \"{$target->key()}\" is taken or not a lower-case key.");
            }

            $resolved[$target->key()] = $target;
        }

        return $this->resolved = $resolved;
    }
}
