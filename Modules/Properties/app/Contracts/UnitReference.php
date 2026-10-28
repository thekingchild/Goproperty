<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * A unit named on an import sheet (ImportedUnits): the unit on file, or
 * `inFile` for one the same file adds, or an `error` for the agency
 * (unknown, archived, removed, ambiguous building; `aboutBuilding` when it
 * is the building cell that is wrong). `key` is the same for
 * every row naming the same unit (duplicates in a file); `name` reads
 * "Flat 1, Palm Court".
 */
final readonly class UnitReference
{
    public function __construct(
        public ?UnitSummary $unit,
        public bool $inFile,
        public ?string $key,
        public ?string $name,
        public ?string $error,
        public bool $aboutBuilding = false,
    ) {}

    public static function refused(string $error, bool $aboutBuilding = false): self
    {
        return new self(null, false, null, null, $error, $aboutBuilding);
    }
}
