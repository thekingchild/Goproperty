<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

use Modules\Import\Contracts\ImportRun;

/**
 * How another module's import sheet names a unit (DATA_IMPORT.md § 1: the
 * Leases sheet, P03-13a): by its building (name or Your ID) and its unit
 * label, the way the Units sheet does, without reading the Properties
 * module's tables (ARCHITECTURE.md § 8 rule 3). During a dry run a unit
 * the same file adds (Buildings and Units sheets) is found too, as
 * `inFile` with no summary yet; during a commit those sheets are already
 * saved. Answers for the current organization only. Bound in
 * PropertiesServiceProvider.
 */
interface ImportedUnits
{
    public function resolve(string $building, string $unit, ImportRun $run): UnitReference;
}
