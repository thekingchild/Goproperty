<?php

declare(strict_types=1);

namespace Modules\Properties\Import;

use App\Support\Money;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Events\UnitDeleted;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\UnitDetails;
use Modules\Properties\Units\Units;
use WeakMap;

/**
 * The Units sheet (DATA_IMPORT.md § 1 row 3): one unit per row, in a
 * building named by the Buildings sheet or a building already on file. A
 * row matches a unit by its `external_ref` ("Your ID") first, then by
 * building + unit label (compared without case, as the unique index does),
 * archived units included: an archived match is restored (P02 decision 3,
 * audited `unit.restored` in the properties log, UnitCreated with
 * `restored`) and carries the batch's id. A unit never moves to another
 * building by import.
 *
 * Rent and service charge are the unit's yearly asking amounts in kobo
 * (DATABASE_SCHEMA.md § 3), read through ImportRow::amountKobo (Money). The
 * status follows P02-02's rule: people (and this sheet) choose vacant,
 * under maintenance or unavailable; occupied and reserved come only from a
 * lease (Leasing, or the Leases sheet of P03-13a), so the sheet imports
 * them as vacant with a warning and never changes the status of a unit a
 * lease holds. Units of an archived building are skipped with a warning.
 *
 * Dry-run warnings: the rent on file differs from the file's; the units
 * the file adds raise the GoProperty bill (once, with the current billable
 * count from UnitDirectory::countBillable(); the import page prices the
 * total with the plan catalogue, P02 decision 6). Every created or
 * restored unit sends UnitCreated after the chunk commits and every unit a
 * rollback archives sends UnitDeleted (P06 metering), as the units writer
 * does. Creates and updates are audited by the batch (`import.committed`).
 *
 * The dry run remembers every valid row under the key `units` (`ref:…`,
 * `label:<building>|<label>`) for the Leases sheet (P03-13a).
 */
final class UnitsImportTarget implements ImportTarget
{
    public const string KEY = 'units';

    private const string ROWS_BY_REF = 'units:ref';

    private const string ROWS_BY_LABEL = 'units:label';

    /** Dry-run memory: the bill warning was given. */
    private const string BILL_WARNED = 'units:bill';

    /** @var WeakMap<ImportRun, array<string, array{building: Property|null, inFile: bool, key: string|null, name: string|null, error: string|null}>> buildings by reference, per run */
    private WeakMap $buildings;

    public function __construct(
        private readonly PropertiesImportTarget $properties,
        private readonly UnitDirectory $directory,
    ) {
        $this->buildings = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Units';
    }

    /** After buildings, before tenants and leases (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 30;
    }

    public function instructions(): string
    {
        return 'One row per unit (flat, shop, office, …), with its building as named on the Buildings sheet. GoProperty recognises a unit by Your ID, then its building and unit name, so importing it again updates it instead of adding it twice. Leave Status empty for vacant units: occupied and reserved come from leases. Each unit counts towards your GoProperty bill.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('U-001'),
            new ImportColumn('building', 'Building', required: true, aliases: ['building name', 'property', 'property name', 'estate', 'estate name'], example: 'Palm Court', help: 'The building\'s name or Your ID from the Buildings sheet (or a building already on GoProperty).'),
            new ImportColumn('unit_label', 'Unit', required: true, aliases: ['unit label', 'unit name', 'unit number', 'unit no', 'flat', 'flat number', 'flat no', 'apartment', 'apartment number', 'shop number', 'label', 'house number'], example: 'Flat 1', help: 'The unit\'s name in its building, such as Flat 3B or Shop 2. Each unit of a building needs its own name.'),
            new ImportColumn('unit_type', 'Type', required: true, aliases: ['unit type', 'type of unit', 'apartment type', 'category'], example: UnitType::Apartment->label(), options: array_map(static fn (UnitType $type): string => $type->label(), UnitType::cases())),
            new ImportColumn('bedrooms', 'Bedrooms', aliases: ['beds', 'bedroom', 'no of bedrooms', 'number of bedrooms', 'rooms'], example: '3'),
            new ImportColumn('bathrooms', 'Bathrooms', aliases: ['baths', 'bathroom', 'toilets', 'no of bathrooms'], example: '2'),
            new ImportColumn('size_sqm', 'Size (sq m)', aliases: ['size', 'sqm', 'size sqm', 'square metres', 'square meters', 'area'], example: '120'),
            new ImportColumn('floor', 'Floor', aliases: ['level', 'storey', 'floor number'], example: '1st'),
            new ImportColumn('rent', 'Rent per year', required: true, aliases: ['rent', 'annual rent', 'yearly rent', 'rent per annum', 'rent amount', 'rent (yearly)', 'rent pa', 'price'], example: '₦1,500,000', help: 'The unit\'s yearly asking rent in naira (0 if none). A lease keeps its own amount.'),
            new ImportColumn('service_charge', 'Service charge per year', aliases: ['service charge', 'annual service charge', 'yearly service charge', 'service charge per annum', 'service fee'], example: '₦150,000', help: 'Yearly, in naira. Empty: none.'),
            new ImportColumn('status', 'Status', aliases: ['unit status', 'occupancy', 'availability'], example: UnitStatus::Vacant->label(), help: 'Empty: vacant. Occupied and reserved come from the unit\'s lease.', options: array_map(static fn (UnitStatus $status): string => $status->label(), UnitStatus::manual())),
            new ImportColumn('description', 'Description', aliases: ['notes', 'details', 'remarks'], example: 'Corner flat with a balcony'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = ImportCells::refIssues($row, $run, self::ROWS_BY_REF, 'unit');
        $label = ImportCells::collapsed($row->text('unit_label'));
        $reference = ImportCells::collapsed($row->text('building'));
        $building = $reference !== null ? $this->building($reference, $run) : null;

        if ($reference === null) {
            $issues[] = RowIssue::error('building', __('Enter the unit\'s building: its name or Your ID from the Buildings sheet.'));
        } elseif ($building !== null && $building['error'] !== null) {
            $issues[] = RowIssue::error('building', $building['error']);
        }

        if ($label === null) {
            $issues[] = RowIssue::error('unit_label', __('Enter the unit\'s name, such as Flat 3B.'));
        } else {
            $issues = [...$issues, ...ImportCells::plainText('unit_label', $label, UnitDetails::MAX_LABEL, '<>{}')];
        }

        if (($type = $row->text('unit_type')) !== null && self::unitType($type) === null) {
            $issues[] = RowIssue::error('unit_type', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (UnitType $t): string => $t->label(), UnitType::cases()))]));
        }

        if (($status = $row->text('status')) !== null && self::status($status) === null) {
            $issues[] = RowIssue::error('status', __('Choose one of: :options. Occupied and reserved come from the unit\'s lease.', ['options' => implode(', ', array_map(static fn (UnitStatus $s): string => $s->label(), UnitStatus::manual()))]));
        }

        $issues = [
            ...$issues,
            ...self::countIssues($row, 'bedrooms', 0, 20),
            ...self::countIssues($row, 'bathrooms', 0, 20),
            ...self::countIssues($row, 'size_sqm', 1, 100_000),
            ...ImportCells::plainText('floor', ImportCells::collapsed($row->text('floor')), 20),
            ...self::amountIssues($row, 'rent'),
            ...self::amountIssues($row, 'service_charge'),
            ...ImportCells::plainText('description', $row->text('description'), 2000, ''),
        ];

        if ($label === null || $building === null || ImportCells::hasErrors($issues)) {
            return $issues;
        }

        if ($building['building']?->isArchived() === true) {
            return [...$issues, RowIssue::warning('building', __(':name is archived, so this unit is skipped. Restore the building on its page first to add or change its units.', ['name' => $building['building']->name]))];
        }

        $existing = $this->match($row, $building['building']);

        if ($existing !== null && $existing->property_id !== $building['building']?->id) {
            return [...$issues, RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is :label of :building. A unit cannot move to another building: check the ID or the building.', [
                'label' => $existing->unit_label,
                'building' => $existing->property->name ?? __('another building'),
            ]))];
        }

        $labelKey = $building['key'].'|'.ImportCells::nameKey($label);

        if (($earlier = $run->rowOf(self::ROWS_BY_LABEL, $labelKey)) !== null) {
            $issues[] = RowIssue::error('unit_label', __(':label of :building is already on row :row. Each unit goes on one row.', ['label' => $label, 'building' => $reference, 'row' => $earlier]));
        } else {
            $run->remember(self::ROWS_BY_LABEL, $labelKey, $row->number);
        }

        if ($existing !== null && ImportCells::nameKey($existing->unit_label) !== ImportCells::nameKey($label)) {
            // Renamed through its ID: the new label must be free in the building.
            $taken = Unit::query()->withTrashed()->where('property_id', $existing->property_id)->where('unit_label', $label)->whereKeyNot($existing->id)->exists();

            if ($taken) {
                $issues[] = RowIssue::error('unit_label', Units::takenMessage($label, $existing->property));
            }
        }

        $issues = [...$issues, ...$this->unitIssues($row, $run, $existing)];

        if (! ImportCells::hasErrors($issues)) {
            if (($ref = $row->externalRef()) !== null) {
                $run->remember(self::KEY, 'ref:'.$ref, $row->number);
            }

            $run->remember(self::KEY, 'label:'.$labelKey, $row->number);
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $building = $this->building((string) $row->text('building'), $run);
        $property = $building['building'];

        if ($property?->isArchived() === true) {
            return new RowPlan(ImportAction::Skip, reason: __(':name is archived.', ['name' => $property->name]));
        }

        $existing = $this->match($row, $property);
        $action = self::action($existing);
        $type = ($text = $row->text('unit_type')) !== null ? self::unitType($text) : null;
        $rent = $row->amountKobo('rent');
        $charge = $row->amountKobo('service_charge');

        return new RowPlan(
            action: $action,
            preview: [
                'building' => $building['name'],
                'unit_label' => ImportCells::collapsed($row->text('unit_label')),
                'unit_type' => ($type ?? $existing?->unit_type)?->label(),
                'bedrooms' => ($bedrooms = $row->integer('bedrooms') ?? $existing?->bedrooms) !== null ? (string) $bedrooms : null,
                'rent' => ($rent !== null ? Money::kobo($rent) : $existing?->base_rent_kobo)?->format(),
                'service_charge' => ($charge !== null ? Money::kobo($charge) : $existing?->service_charge_kobo)?->format(),
                'status' => self::statusFor($row, $existing)->label(),
            ],
            // Each unit added or brought back counts towards the bill (BILLING_PAYMENTS.md § 1.4).
            units: $action === ImportAction::Update ? 0 : 1,
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $found = $this->building((string) $row->text('building'), $run)['building'];

        // The building row is locked while its units change, as the units writer does.
        $building = $found !== null ? Property::query()->whereKey($found->id)->lockForUpdate()->first() : null;

        if ($building === null) {
            return new UpsertResult(ImportAction::Skip, reason: __('The building is no longer on file.'));
        }

        if ($building->isArchived()) {
            return new UpsertResult(ImportAction::Skip, reason: __(':name is archived.', ['name' => $building->name]));
        }

        $existing = $this->match($row, $building);
        $action = self::action($existing);
        $unit = $existing ?? new Unit;

        $unit->fill(self::attributes($row));
        $unit->unit_label = (string) ImportCells::collapsed($row->text('unit_label'));
        $unit->status = self::statusFor($row, $existing);

        if (! $unit->exists) {
            $unit->property_id = $building->id;
        }

        if (($ref = $row->externalRef()) !== null) {
            $unit->external_ref = $ref;
        }

        if ($action !== ImportAction::Update) {
            $unit->import_batch_id = $run->batchId();
            $unit->deleted_at = null;
        }

        $unit->save();

        if ($action === ImportAction::Restore) {
            activity(Units::LOG)
                ->event('unit.restored')
                ->performedOn($unit)
                ->withProperties(['unit' => $unit->unit_label, 'property_id' => $unit->property_id, 'import_batch_id' => $run->batchId(), 'row' => $row->number])
                ->log('Unit restored by an import');
        }

        if ($action !== ImportAction::Update) {
            UnitCreated::dispatch($unit->organization_id, $unit->property_id, $unit->id, restored: $action === ImportAction::Restore);
        }

        return new UpsertResult($action, $unit->id);
    }

    public function rollback(int $batchId): int
    {
        $units = Unit::query()->where('import_batch_id', $batchId)->get(['id', 'organization_id', 'property_id']);

        Unit::query()->where('import_batch_id', $batchId)->delete();

        foreach ($units as $unit) {
            // They stop counting towards the bill, as when archived on the pages.
            UnitDeleted::dispatch($unit->organization_id, $unit->property_id, $unit->id);
        }

        return $units->count();
    }

    /**
     * A unit of the batch is used once a lease holds it (occupied or
     * reserved, set by Leasing through UnitDirectory::setStatus). P03-13a
     * adds leases, bills and payments through their modules' contracts.
     */
    public function usedSince(int $batchId): bool
    {
        return Unit::query()->withTrashed()->where('import_batch_id', $batchId)
            ->whereIn('status', [UnitStatus::Occupied->value, UnitStatus::Reserved->value])
            ->exists();
    }

    /**
     * The row's building, looked up once per reference in a run (the
     * Buildings sheet is done before the Units sheet starts).
     *
     * @return array{building: Property|null, inFile: bool, key: string|null, name: string|null, error: string|null}
     */
    private function building(string $reference, ImportRun $run): array
    {
        $key = ImportCells::nameKey($reference);
        $known = $this->buildings[$run] ?? [];

        if (! isset($known[$key])) {
            $known[$key] = $this->properties->resolve($reference, $run);
            $this->buildings[$run] = $known;
        }

        return $known[$key];
    }

    /** The unit the row is about: by Your ID, then by building + label, archived units included. */
    private function match(ImportRow $row, ?Property $building): ?Unit
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($unit = Unit::query()->withTrashed()->with('property')->where('external_ref', $ref)->first()) !== null) {
            return $unit;
        }

        if ($building === null) {
            return null; // A building the file adds has no units yet.
        }

        return Unit::query()->withTrashed()->where('property_id', $building->id)->where('unit_label', ImportCells::collapsed($row->text('unit_label')))->first();
    }

    /** @return list<RowIssue> */
    private function unitIssues(ImportRow $row, ImportRun $run, ?Unit $existing): array
    {
        $issues = [];
        $status = ($text = $row->text('status')) !== null ? self::status($text) : null;

        if ($existing === null) {
            if ($row->text('unit_type') === null) {
                $issues[] = RowIssue::error('unit_type', __('Choose the unit\'s type.'));
            }

            if ($row->text('rent') === null) {
                $issues[] = RowIssue::error('rent', __('Enter the unit\'s yearly rent (0 if there is none).'));
            }
        }

        if ($existing?->trashed() === true) {
            $issues[] = RowIssue::warning(null, __('This unit was archived. Importing brings it back, and it counts towards your bill again.'));
        }

        if ($existing !== null && ($ref = $row->externalRef()) !== null && $existing->external_ref !== null && $existing->external_ref !== $ref) {
            $issues[] = RowIssue::warning(ImportColumn::EXTERNAL_REF, __('This unit was imported before with ID ":old". Its ID becomes ":new".', ['old' => $existing->external_ref, 'new' => $ref]));
        }

        if ($existing !== null && ($rent = $row->amountKobo('rent')) !== null && $rent !== $existing->base_rent_kobo->kobo) {
            $issues[] = RowIssue::warning('rent', __('The rent on file is :old a year; this import changes it to :new.', ['old' => $existing->base_rent_kobo->format(), 'new' => Money::kobo($rent)->format()]));
        }

        if ($existing !== null && $existing->status->heldByLease()) {
            if ($status !== null && $status !== $existing->status) {
                $issues[] = RowIssue::warning('status', __('A lease holds this unit, so its status stays :status: the Status cell is ignored.', ['status' => mb_strtolower($existing->status->label())]));
            }
        } elseif ($status?->heldByLease() === true) {
            $issues[] = RowIssue::warning('status', __(':Status comes from the unit\'s lease, so this unit is imported as :fallback. Importing its lease marks it :status.', [
                'status' => mb_strtolower($status->label()),
                'fallback' => mb_strtolower(self::statusFor($row, $existing)->label()),
            ]));
        }

        if ($run->dryRun && ($existing === null || $existing->trashed()) && ! $run->knows(self::BILL_WARNED, 'given') && ! ImportCells::hasErrors($issues)) {
            $run->remember(self::BILL_WARNED, 'given', $row->number);
            $issues[] = RowIssue::warning(null, __('Each unit this import adds or brings back counts towards your GoProperty bill. You have :count billable units now; the summary shows the total and the monthly price with this file\'s units.', ['count' => number_format($this->directory->countBillable())]));
        }

        return $issues;
    }

    /** @return list<RowIssue> */
    private static function countIssues(ImportRow $row, string $column, int $min, int $max): array
    {
        try {
            $value = $row->integer($column);
        } catch (InvalidCellValue $invalid) {
            return [RowIssue::error($column, $invalid->getMessage())];
        }

        return $value !== null && ($value < $min || $value > $max)
            ? [RowIssue::error($column, __('Enter a whole number from :min to :max.', ['min' => number_format($min), 'max' => number_format($max)]))]
            : [];
    }

    /** @return list<RowIssue> */
    private static function amountIssues(ImportRow $row, string $column): array
    {
        try {
            $kobo = $row->amountKobo($column);
        } catch (InvalidCellValue $invalid) {
            return [RowIssue::error($column, $invalid->getMessage())];
        }

        return match (true) {
            $kobo === null => [],
            $kobo < 0 => [RowIssue::error($column, $column === 'rent' ? __('The rent cannot be negative.') : __('The service charge cannot be negative.'))],
            $kobo > Money::naira(UnitDetails::MAX_NAIRA)->kobo => [RowIssue::error($column, __('Enter an amount below ₦10,000,000,000.'))],
            default => [],
        };
    }

    /**
     * The status the unit gets: a lease's status stays; otherwise the
     * file's manual status, or what is on file, or vacant. Occupied and
     * reserved in the file are not set here (P02-02: only Leasing).
     */
    private static function statusFor(ImportRow $row, ?Unit $existing): UnitStatus
    {
        if ($existing !== null && $existing->status->heldByLease()) {
            return $existing->status;
        }

        $status = ($text = $row->text('status')) !== null ? self::status($text) : null;

        if ($status !== null && ! $status->heldByLease()) {
            return $status;
        }

        return $existing->status ?? UnitStatus::Vacant;
    }

    /** A unit type as listed, or as agencies write it ("Apartment", "Self con", "Mini flat"). */
    private static function unitType(string $text): ?UnitType
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($text));

        foreach (UnitType::cases() as $type) {
            if ($key === preg_replace('/[^a-z]/', '', mb_strtolower($type->label())) || $key === str_replace('_', '', $type->value)) {
                return $type;
            }
        }

        return match ($key) {
            'flats', 'miniflat', 'bedroomflat', 'roomandparlour', 'penthouse', 'duplex', 'terrace', 'maisonette' => UnitType::Apartment,
            'selfcon', 'selfcontained', 'selfcontainroom', 'bedsitter', 'studio', 'room', 'singleroom' => UnitType::SelfContain,
            'store', 'kiosk', 'shopspace', 'retail' => UnitType::Shop,
            'officespace', 'offices', 'workspace' => UnitType::Office,
            default => null,
        };
    }

    /** Any status, as listed or as agencies write it; occupied and reserved are recognised so they can be explained. */
    private static function status(string $text): ?UnitStatus
    {
        $key = (string) preg_replace('/[^a-z]/', '', mb_strtolower($text));

        return match ($key) {
            'vacant', 'available', 'empty', 'free', 'tolet', 'unoccupied' => UnitStatus::Vacant,
            'undermaintenance', 'maintenance', 'underrepair', 'repair', 'repairs', 'underrenovation', 'renovation' => UnitStatus::UnderMaintenance,
            'unavailable', 'notavailable', 'offmarket', 'blocked', 'notforrent' => UnitStatus::Unavailable,
            'occupied', 'let', 'rented', 'taken', 'tenanted' => UnitStatus::Occupied,
            'reserved', 'booked', 'onhold' => UnitStatus::Reserved,
            default => null,
        };
    }

    private static function action(?Unit $existing): ImportAction
    {
        return match (true) {
            $existing === null => ImportAction::Create,
            $existing->trashed() => ImportAction::Restore,
            default => ImportAction::Update,
        };
    }

    /**
     * What the row sets, besides the label and status: only the cells the
     * file fills in (an empty cell keeps what is on file; a new unit's
     * service charge is then zero, the column's default).
     *
     * @return array<string, mixed>
     */
    private static function attributes(ImportRow $row): array
    {
        $values = [];

        if (($type = $row->text('unit_type')) !== null) {
            $values['unit_type'] = self::unitType($type);
        }

        foreach (['bedrooms', 'bathrooms', 'size_sqm'] as $column) {
            if (($number = $row->integer($column)) !== null) {
                $values[$column] = $number;
            }
        }

        if (($floor = ImportCells::collapsed($row->text('floor'))) !== null) {
            $values['floor'] = $floor;
        }

        foreach (['rent' => 'base_rent_kobo', 'service_charge' => 'service_charge_kobo'] as $cell => $column) {
            if (($kobo = $row->amountKobo($cell)) !== null) {
                $values[$column] = Money::kobo($kobo);
            }
        }

        if (($description = $row->text('description')) !== null) {
            $values['description'] = $description;
        }

        return $values;
    }
}
