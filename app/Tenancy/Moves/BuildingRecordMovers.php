<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The registry of MovesBuildingRecords implementations (ARCHITECTURE.md
 * § 6.1). A module registers its mover from its service provider with
 * callAfterResolving(BuildingRecordMovers::class, …); Core registers the
 * media library's (App\Media\MediaMover). Bound as a singleton.
 */
final class BuildingRecordMovers
{
    /** @var array<class-string<MovesBuildingRecords>, true> */
    private array $movers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<MovesBuildingRecords>  $mover */
    public function register(string $mover): void
    {
        if (! is_subclass_of($mover, MovesBuildingRecords::class)) {
            throw new InvalidArgumentException("{$mover} does not move building records.");
        }

        $this->movers[$mover] = true;
    }

    /**
     * Every registered mover, in the order they run (then by class name, so
     * the order never depends on which provider booted first).
     *
     * @return list<MovesBuildingRecords>
     */
    public function all(): array
    {
        $movers = array_map(fn (string $class): MovesBuildingRecords => $this->resolve($class), array_keys($this->movers));

        usort($movers, static fn (MovesBuildingRecords $a, MovesBuildingRecords $b): int => [$a->order(), $a::class] <=> [$b->order(), $b::class]);

        return $movers;
    }

    /** @param  class-string<MovesBuildingRecords>  $class */
    private function resolve(string $class): MovesBuildingRecords
    {
        $mover = $this->container->make($class);
        assert($mover instanceof MovesBuildingRecords);

        return $mover;
    }
}
