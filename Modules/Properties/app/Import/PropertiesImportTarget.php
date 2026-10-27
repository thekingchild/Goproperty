<?php

declare(strict_types=1);

namespace Modules\Properties\Import;

use App\Support\NigerianStates;
use Illuminate\Database\Eloquent\Builder;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Properties\Buildings\Amenities;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use WeakMap;

/**
 * The Buildings sheet (DATA_IMPORT.md § 1 row 2, `properties`): one
 * building per row, its owner named by the Owners sheet or an owner already
 * on file (empty: the organization owns it). A row matches a building by
 * its `external_ref` ("Your ID") first, then by name within the
 * organization, buildings removed by an import rollback (soft deleted)
 * included: such a match is restored (P02 decision 3, audited
 * `property.restored` in the properties log) and carries the batch's id.
 *
 * A building archived on its page (`status = archived`, ARCHITECTURE.md
 * § 6.2) is skipped with a warning rather than brought back: restoring it
 * would put its units back on the GoProperty bill (BILLING_PAYMENTS.md
 * § 1.4), and a rollback could not archive it again. The agency restores it
 * on its page first. Its units are skipped the same way (UnitsImportTarget).
 *
 * New buildings get a free slug from the buildings writer
 * (Buildings\Properties::freeSlug). An empty cell keeps what is on file;
 * creates and updates are audited by the batch (`import.committed`), like
 * the tenants sheet (P02-06b).
 *
 * The dry run remembers every valid row under the key `properties`
 * (`ref:…`, `name:…`) so the Units sheet can name a building the file adds
 * (resolve()).
 */
final class PropertiesImportTarget implements ImportTarget
{
    public const string KEY = 'properties';

    private const string ROWS_BY_REF = 'properties:ref';

    private const string ROWS_BY_NAME = 'properties:name';

    /** @var WeakMap<ImportRun, array<string, string>> per dry run: the names of the buildings the file adds, by remembered key */
    private WeakMap $names;

    public function __construct(
        private readonly OwnersImportTarget $owners,
        private readonly Properties $writer,
    ) {
        $this->names = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Buildings';
    }

    /** After owners, before units (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 20;
    }

    public function instructions(): string
    {
        return 'One row per building. Name its owner as on the Owners sheet (or an owner already on GoProperty), or leave Owner empty when your organization owns it. GoProperty recognises a building by Your ID, then its name, so importing it again updates it instead of adding it twice.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('B-001'),
            new ImportColumn('name', 'Building name', required: true, aliases: ['building', 'property', 'property name', 'name', 'estate', 'estate name', 'building/property name'], example: 'Palm Court', help: 'Each building in the file needs its own name: units name their building by it.'),
            new ImportColumn('owner', 'Owner', aliases: ['owner name', 'landlord', 'landlord name', 'property owner', 'owner phone', 'owner id'], example: 'Bola Adeyemi', help: 'The owner\'s name, phone number or Your ID from the Owners sheet. Empty: your organization owns the building.'),
            new ImportColumn('property_type', 'Type', required: true, aliases: ['building type', 'property type', 'type of building', 'type of property'], example: PropertyType::BlockOfFlats->label(), options: array_map(static fn (PropertyType $type): string => $type->label(), PropertyType::cases())),
            new ImportColumn('address', 'Address', required: true, aliases: ['street address', 'building address', 'property address', 'location'], example: '5 Palm Avenue, Lekki Phase 1'),
            new ImportColumn('state', 'State', required: true, aliases: ['building state'], example: 'Lagos', options: NigerianStates::ALL),
            new ImportColumn('lga', 'LGA', aliases: ['local government', 'local government area'], example: 'Eti-Osa'),
            new ImportColumn('landmark', 'Landmark', aliases: ['nearest landmark', 'close to', 'near'], example: 'Opposite the Lekki toll gate'),
            new ImportColumn('amenities', 'Amenities', aliases: ['facilities', 'features'], example: 'Borehole, Security, Parking', help: 'Separate them with commas.'),
            new ImportColumn('year_built', 'Year built', aliases: ['year', 'built in', 'year of construction', 'built'], example: '2015'),
            new ImportColumn('description', 'Description', aliases: ['notes', 'details', 'about'], example: 'Six flats over three floors'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = ImportCells::refIssues($row, $run, self::ROWS_BY_REF, 'building');
        $name = ImportCells::collapsed($row->text('name'));

        if ($name === null) {
            $issues[] = RowIssue::error('name', __('Enter the building\'s name.'));
        } elseif (mb_strlen($name) < 2) {
            $issues[] = RowIssue::error('name', __('Use at least 2 characters for the building\'s name.'));
        } else {
            $issues = [...$issues, ...ImportCells::plainText('name', $name, 160)];

            if (($earlier = $run->rowOf(self::ROWS_BY_NAME, $name)) !== null) {
                $issues[] = RowIssue::error('name', __('A building with this name is already on row :row. Give each building its own name: units name their building by it.', ['row' => $earlier]));
            } else {
                $run->remember(self::ROWS_BY_NAME, $name, $row->number);
            }
        }

        if (($type = $row->text('property_type')) !== null && self::propertyType($type) === null) {
            $issues[] = RowIssue::error('property_type', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (PropertyType $t): string => $t->label(), PropertyType::cases()))]));
        }

        $issues = [
            ...$issues,
            ...ImportCells::plainText('address', ImportCells::collapsed($row->text('address')), 300),
            ...ImportCells::stateIssues($row),
            ...ImportCells::plainText('lga', ImportCells::collapsed($row->text('lga')), 64),
            ...ImportCells::plainText('landmark', ImportCells::collapsed($row->text('landmark')), 160),
            ...ImportCells::plainText('description', $row->text('description'), 2000, ''),
            ...self::amenityIssues($row),
            ...self::yearIssues($row),
        ];

        if (($owner = $row->text('owner')) !== null && ($error = $this->owners->resolve($owner, $run)['error']) !== null) {
            $issues[] = RowIssue::error('owner', $error);
        }

        if ($name === null || ImportCells::hasErrors($issues)) {
            return $issues;
        }

        [$existing, $ambiguous] = $this->match($row);

        if ($ambiguous !== null) {
            return [...$issues, RowIssue::error('name', $ambiguous)];
        }

        if ($existing === null) {
            foreach (['property_type' => __('Choose the building\'s type.'), 'address' => __('Enter the building\'s address.'), 'state' => __('Choose the building\'s state.')] as $column => $message) {
                if ($row->text($column) === null) {
                    $issues[] = RowIssue::error($column, $message);
                }
            }
        } elseif ($existing->isArchived()) {
            return [...$issues, RowIssue::warning(null, __(':name is archived, so this row is skipped (and so are its units). Restore the building on its page first to import changes to it.', ['name' => $existing->name]))];
        } elseif ($existing->trashed()) {
            $issues[] = RowIssue::warning(null, __('This building was removed when an import was rolled back. Importing brings it back.'));
        }

        if ($existing !== null && ($ref = $row->externalRef()) !== null && $existing->external_ref !== null && $existing->external_ref !== $ref) {
            $issues[] = RowIssue::warning(ImportColumn::EXTERNAL_REF, __('This building was imported before with ID ":old". Its ID becomes ":new".', ['old' => $existing->external_ref, 'new' => $ref]));
        }

        if ($run->dryRun && ! ImportCells::hasErrors($issues)) {
            $named = $this->names[$run] ?? [];

            $keys = ['name:'.ImportCells::nameKey($name)];

            if (($ref = $row->externalRef()) !== null) {
                $keys[] = 'ref:'.$ref;
            }

            foreach ($keys as $key) {
                $run->remember(self::KEY, $key, $row->number);
                $named[$key] ??= $name;
            }

            $this->names[$run] = $named;
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        [$existing] = $this->match($row);
        $type = ($text = $row->text('property_type')) !== null ? self::propertyType($text) : null;

        if ($existing?->isArchived() === true) {
            return new RowPlan(ImportAction::Skip, reason: __(':name is archived.', ['name' => $existing->name]));
        }

        $owner = ($reference = $row->text('owner')) !== null ? $this->owners->resolve($reference, $run)['name'] : $existing?->owner?->display_name;

        return new RowPlan(
            action: self::action($existing),
            preview: [
                'name' => ImportCells::collapsed($row->text('name')),
                'owner' => $owner,
                'property_type' => ($type ?? $existing?->property_type)?->label(),
                'address' => ImportCells::collapsed($row->text('address')) ?? $existing?->address,
                'state' => ImportCells::state($row->text('state')) ?? $existing?->state,
            ],
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        [$existing] = $this->match($row);

        if ($existing?->isArchived() === true) {
            return new UpsertResult(ImportAction::Skip, $existing->id, __(':name is archived.', ['name' => $existing->name]));
        }

        $action = self::action($existing);
        $property = $existing ?? new Property;
        $property->fill(self::attributes($row));

        if (($reference = $row->text('owner')) !== null) {
            // validate() has just found the owner on file (the Owners sheet is committed first).
            $property->owner_profile_id = $this->owners->resolve($reference, $run)['owner']?->id;
        }

        if (! $property->exists) {
            $property->slug = $this->writer->freeSlug($property->name);
        }

        if (($ref = $row->externalRef()) !== null) {
            $property->external_ref = $ref;
        }

        if ($action !== ImportAction::Update) {
            $property->import_batch_id = $run->batchId();
            $property->deleted_at = null;
        }

        $property->save();

        if ($action === ImportAction::Restore) {
            activity(Properties::LOG)
                ->event('property.restored')
                ->performedOn($property)
                ->withProperties(['building' => $property->name, 'import_batch_id' => $run->batchId(), 'row' => $row->number])
                ->log('Building restored by an import');
        }

        return new UpsertResult($action, $property->id);
    }

    /** Units go first (UnitsImportTarget, reverse order), so only the batch's own units were in these buildings. */
    public function rollback(int $batchId): int
    {
        return Property::query()->where('import_batch_id', $batchId)->delete();
    }

    /**
     * A building of the batch is used once a unit that is not the batch's
     * own is in it (added on the pages or by a later import): removing the
     * building would strand that unit, which still counts towards the bill.
     */
    public function usedSince(int $batchId): bool
    {
        return Unit::query()
            ->whereIn('property_id', Property::query()->withTrashed()->where('import_batch_id', $batchId)->select('id'))
            ->where(static fn (Builder $query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
            ->exists();
    }

    /**
     * The building a Units row names in its Building cell: by Your ID, then
     * name; in a dry run also a building the Buildings sheet of the same file
     * adds or brings back. `key` identifies the building for duplicate unit
     * labels in the file; `building` is null with `inFile` true for a
     * building the file adds; `name` is its name as it will be; `error`
     * explains a reference that cannot be used (unknown, ambiguous, removed).
     *
     * @return array{building: Property|null, inFile: bool, key: string|null, name: string|null, error: string|null}
     */
    public function resolve(string $reference, ImportRun $run): array
    {
        $reference = (string) ImportCells::collapsed($reference);
        $named = $this->names[$run] ?? [];
        $key = 'ref:'.$reference;
        $building = Property::query()->withTrashed()->where('external_ref', $reference)->first();

        if ($building === null && ! isset($named[$key])) {
            $buildings = self::byName($reference)->limit(2)->get();

            if ($buildings->count() > 1) {
                return ['building' => null, 'inFile' => false, 'key' => null, 'name' => null, 'error' => ImportCells::message('More than one building is called ":building". Use the building\'s Your ID.', ['building' => $reference])];
            }

            $building = $buildings->first();
            $key = 'name:'.ImportCells::nameKey($reference);
        }

        if ($building !== null && ! isset($named[$key])) {
            // Found by its ID; the Buildings sheet may name it without one.
            $key = 'name:'.ImportCells::nameKey($building->name);
        }

        $fileRow = isset($named[$key]) ? $run->rowOf(self::KEY, $key) : null;
        $name = $fileRow !== null ? $named[$key] : $building?->name;

        if ($building !== null) {
            return $building->trashed() && $fileRow === null && ! $building->isArchived()
                ? ['building' => $building, 'inFile' => false, 'key' => null, 'name' => $name, 'error' => ImportCells::message(':name was removed when an import was rolled back. Add it to the Buildings sheet to bring it back.', ['name' => $building->name])]
                : ['building' => $building, 'inFile' => $fileRow !== null, 'key' => 'id:'.$building->id, 'name' => $name, 'error' => null];
        }

        if ($fileRow !== null) {
            return ['building' => null, 'inFile' => true, 'key' => 'row:'.$fileRow, 'name' => $name, 'error' => null];
        }

        return ['building' => null, 'inFile' => false, 'key' => null, 'name' => null, 'error' => ImportCells::message('No building is called ":building", here or in the Buildings sheet. Add it to the Buildings sheet first.', ['building' => mb_substr($reference, 0, 80)])];
    }

    /**
     * The building the row is about, removed ones included, or an
     * explanation when its name is shared by more than one building.
     *
     * @return array{0: Property|null, 1: string|null}
     */
    private function match(ImportRow $row): array
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($property = Property::query()->withTrashed()->where('external_ref', $ref)->first()) !== null) {
            return [$property, null];
        }

        $buildings = self::byName((string) $row->text('name'))->limit(2)->get();

        return $buildings->count() > 1
            ? [null, ImportCells::message('More than one building is called :name. Add the Your ID of the one you mean.', ['name' => ImportCells::collapsed($row->text('name'))])]
            : [$buildings->first(), null];
    }

    /** @return Builder<Property> buildings with this name (compared without case), removed ones included */
    private static function byName(string $name): Builder
    {
        return Property::query()->withTrashed()->where('name', ImportCells::collapsed($name));
    }

    /** @return list<RowIssue> */
    private static function amenityIssues(ImportRow $row): array
    {
        $text = $row->text('amenities');

        if ($text === null) {
            return [];
        }

        $amenities = Amenities::normalize([], str_replace(';', ',', $text));

        foreach ($amenities as $amenity) {
            if (preg_match('/[<>]/', $amenity) === 1) {
                return [RowIssue::error('amenities', __('Use plain text only (no < or >).'))];
            }

            if (mb_strlen($amenity) > Amenities::MAX_LENGTH) {
                return [RowIssue::error('amenities', __('Keep each amenity to :max characters, separated by commas.', ['max' => Amenities::MAX_LENGTH]))];
            }
        }

        return count($amenities) > Amenities::MAX_ITEMS
            ? [RowIssue::error('amenities', __('List at most :max amenities.', ['max' => Amenities::MAX_ITEMS]))]
            : [];
    }

    /** @return list<RowIssue> */
    private static function yearIssues(ImportRow $row): array
    {
        $max = (int) now()->format('Y') + 5;

        try {
            $year = $row->integer('year_built');
        } catch (InvalidCellValue) {
            $year = 0;
        }

        return $year !== null && ($year < 1800 || $year > $max)
            ? [RowIssue::error('year_built', __('Enter a year between 1800 and :max, such as 2015.', ['max' => $max]))]
            : [];
    }

    /** A type as listed, or as agencies write it ("Flats", "Mixed", "Office"). */
    private static function propertyType(string $text): ?PropertyType
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($text));

        foreach (PropertyType::cases() as $type) {
            if ($key === preg_replace('/[^a-z]/', '', mb_strtolower($type->label())) || $key === str_replace('_', '', $type->value)) {
                return $type;
            }
        }

        return match ($key) {
            'flats', 'flat', 'blockofflat', 'apartments', 'apartmentblock', 'block' => PropertyType::BlockOfFlats,
            'mixed', 'mixeduse', 'mixeddevelopment' => PropertyType::Mixed,
            'office', 'offices', 'officebuilding', 'shop', 'shops', 'plaza', 'shoppingplaza', 'shoppingcomplex', 'warehouse' => PropertyType::Commercial,
            'detachedduplex', 'semidetachedduplex', 'terrace', 'terraced' => PropertyType::Duplex,
            default => null,
        };
    }

    private static function action(?Property $existing): ImportAction
    {
        return match (true) {
            $existing === null => ImportAction::Create,
            $existing->trashed() => ImportAction::Restore,
            default => ImportAction::Update,
        };
    }

    /**
     * What the row sets: the name always, other cells only when the file
     * fills them in (an empty cell keeps what is on file). The owner is set
     * by upsert().
     *
     * @return array<string, mixed>
     */
    private static function attributes(ImportRow $row): array
    {
        $values = ['name' => ImportCells::collapsed($row->text('name'))];

        if (($type = $row->text('property_type')) !== null) {
            $values['property_type'] = self::propertyType($type);
        }

        foreach (['address', 'lga', 'landmark'] as $column) {
            if (($text = ImportCells::collapsed($row->text($column))) !== null) {
                $values[$column] = $text;
            }
        }

        if (($state = ImportCells::state($row->text('state'))) !== null) {
            $values['state'] = $state;
        }

        if (($description = $row->text('description')) !== null) {
            $values['description'] = $description;
        }

        if (($amenities = $row->text('amenities')) !== null) {
            $values['amenities'] = Amenities::normalize([], str_replace(';', ',', $amenities));
        }

        if (($year = $row->integer('year_built')) !== null) {
            $values['year_built'] = $year;
        }

        return $values;
    }
}
