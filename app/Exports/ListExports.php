<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The registered list exports (a singleton). Core registers its own lists
 * (AppServiceProvider) and each module registers its lists from its
 * service provider, without Core ever naming a module:
 *
 *     $this->callAfterResolving(ListExports::class, fn (ListExports $exports) => $exports->register(OwnerList::class));
 *
 * Classes are kept by name and made from the container each time, so an
 * export never holds a service from an earlier request. A key used twice,
 * or one that is not a lower-case key, is a mistake and throws.
 */
final class ListExports
{
    /** @var array<int, class-string<ListExport>> */
    private array $registered = [];

    /** @var array<string, class-string<ListExport>>|null */
    private ?array $byKey = null;

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<ListExport>  $export */
    public function register(string $export): void
    {
        $this->registered[] = $export;
        $this->byKey = null;
    }

    public function find(string $key): ?ListExport
    {
        $class = $this->keys()[$key] ?? null;

        return $class === null ? null : $this->make($class);
    }

    /** @return array<string, class-string<ListExport>> every key, in key order */
    public function keys(): array
    {
        if ($this->byKey !== null) {
            return $this->byKey;
        }

        $keys = [];

        foreach ($this->registered as $class) {
            $key = $this->make($class)->key();

            if (isset($keys[$key]) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key) !== 1) {
                throw new LogicException("List export key \"{$key}\" is taken or not a lower-case key.");
            }

            $keys[$key] = $class;
        }

        ksort($keys);

        return $this->byKey = $keys;
    }

    /** @param  class-string<ListExport>  $class */
    private function make(string $class): ListExport
    {
        $export = $this->container->make($class);

        if (! $export instanceof ListExport) {
            throw new LogicException('List exports implement '.ListExport::class.'.');
        }

        return $export;
    }
}
