<?php

declare(strict_types=1);

namespace Modules\Properties\Import;

use Modules\Import\Contracts\ImportRun;
use Modules\Properties\Contracts\ImportedUnits;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitReference;
use Modules\Properties\Models\Unit;

/**
 * Finds the unit a Leases row names (P03-13a) the way the Units sheet
 * matches units: the building by Your ID or name (PropertiesImportTarget::
 * resolve, which also knows the buildings the same file adds), then the
 * unit by its label in that building, without case (as the unique index
 * compares). A unit the same file adds is known from the Units sheet's
 * dry-run memory (`units`, `label:<building>|<label>`).
 */
final readonly class ImportedUnitLookup implements ImportedUnits
{
    public function __construct(
        private PropertiesImportTarget $properties,
        private UnitDirectory $units,
    ) {}

    public function resolve(string $building, string $unit, ImportRun $run): UnitReference
    {
        $label = ImportCells::collapsed($unit);
        $found = $this->properties->resolve($building, $run);

        if ($found['error'] !== null) {
            return UnitReference::refused($found['error'], aboutBuilding: true);
        }

        if ($label === null) {
            return UnitReference::refused(ImportCells::message('Enter the unit\'s name, such as Flat 3B.'));
        }

        $buildingName = $found['name'] ?? (string) ImportCells::collapsed($building);
        $name = $label.', '.$buildingName;
        $property = $found['building'];

        if ($property !== null && $property->isArchived()) {
            return UnitReference::refused(ImportCells::message(':building is archived. Restore it on its page before importing its leases.', ['building' => $property->name]), aboutBuilding: true);
        }

        $inFile = $run->knows(UnitsImportTarget::KEY, 'label:'.$found['key'].'|'.ImportCells::nameKey($label));
        $record = $property !== null ? Unit::query()->withTrashed()->where('property_id', $property->id)->where('unit_label', $label)->first() : null;

        if ($record !== null && ! $record->trashed()) {
            $summary = $this->units->find($record->id);

            return $summary !== null
                ? new UnitReference($summary, false, 'id:'.$record->id, $summary->label.', '.$summary->propertyName, null)
                : UnitReference::refused(ImportCells::message('No unit is called ":unit" in :building.', ['unit' => $label, 'building' => $buildingName]));
        }

        if ($inFile) {
            return new UnitReference(null, true, $record !== null ? 'id:'.$record->id : 'row:'.$found['key'].'|'.ImportCells::nameKey($label), $name, null);
        }

        if ($record !== null) {
            return UnitReference::refused(ImportCells::message(':unit of :building was removed when an import was rolled back. Add it to the Units sheet to bring it back.', ['unit' => $record->unit_label, 'building' => $buildingName]));
        }

        return UnitReference::refused(ImportCells::message('No unit is called ":unit" in :building, here or in the Units sheet. Add it to the Units sheet first.', ['unit' => mb_substr($label, 0, 60), 'building' => $buildingName]));
    }
}
