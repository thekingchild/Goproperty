<?php

declare(strict_types=1);

namespace Modules\Billing\Import;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\ImportedUtilitySetting;
use Modules\Billing\Utilities\MeterValue;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Billing\Utilities\UtilityTypes;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Properties\Contracts\ImportedUnits;
use Modules\Properties\Contracts\UnitReference;
use WeakMap;

/**
 * The Utilities sheet (DATA_IMPORT.md § 1 row 7, § 3; P03-13b): which
 * utilities each unit pays, brought over from another system. One row per
 * unit and utility: the unit (building + unit name, from the Units sheet or
 * on file), the utility (its name or code; a utility the organization does
 * not have yet is added, flat or metered, with the row's amount and cycle
 * as its defaults), the amount and cycle (overrides when they differ from
 * the utility's), a metered utility's last meter reading (the starting
 * reading: consumption after it is billed) and "Paid until".
 *
 * Never back-billed (AGENT.md § 4 "Imports"): billing starts with the
 * period after "Paid until" (the import day when empty), and never with a
 * period that started before the import day — `next_billing_date` is always
 * set (P03-06b: a setting with a past `effective_from` and no
 * `next_billing_date` would be billed for every period since). The past
 * days left unbilled are named in a warning (they belong on the Opening
 * balances sheet). Nothing is sent and no reading is recorded by the import.
 *
 * A row matches a setting by Your ID, else by unit + utility: re-importing
 * updates it while it has no bill and no reading, and switches a switched-
 * off one (e.g. by a rollback) back on. Rollback switches the batch's
 * settings off (they are never deleted) and archives the utilities it
 * added. Reads other modules through their contracts only (units through
 * Properties' ImportedUnits).
 */
final class UtilitiesImportTarget implements ImportTarget
{
    public const string KEY = 'utilities';

    private const string ROWS_BY_REF = 'utilities:ref';

    private const string ROWS_BY_UNIT = 'utilities:unit';

    private const int MAX_NAME = 80;

    private const int MAX_REF = 64;

    private const int MAX_NAIRA = 10_000_000_000;

    /** @var WeakMap<ImportRun, array<string, UnitReference>> */
    private WeakMap $unitRefs;

    /**
     * Utility types the file adds, by name key: [metered, unit of measure, amount, cycle, row].
     *
     * @var WeakMap<ImportRun, array<string, array{metered: bool, unit: MeterUnit|null, amount: Money, cycle: BillingCycle, row: int}>>
     */
    private WeakMap $newTypes;

    /**
     * Only stateless collaborators are kept: this target is a singleton, so
     * whatever reads the organization (its context is scoped: a queue
     * worker resets it between jobs) is resolved when used.
     */
    public function __construct(private readonly CycleCalculator $calculator)
    {
        $this->unitRefs = new WeakMap;
        $this->newTypes = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Utilities';
    }

    /** After the leases and opening balances, before the payment history (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 70;
    }

    public function instructions(): string
    {
        return 'One row per unit and utility it pays (security levy, water, electricity…). A utility you do not have yet is added with the row\'s amount and cycle. For a metered utility give the meter\'s last reading: consumption after it is billed. GoProperty bills from the period after "Paid until" (or from the import day) and never bills the past.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('UT-001'),
            new ImportColumn('building', 'Building', required: true, aliases: ['building name', 'property', 'property name', 'estate'], example: 'Palm Court', help: 'The building\'s name or Your ID, from the Buildings sheet or already on GoProperty.'),
            new ImportColumn('unit_label', 'Unit', required: true, aliases: ['unit name', 'unit label', 'unit number', 'flat', 'flat number', 'apartment', 'shop number'], example: 'Flat 1', help: 'The unit\'s name in its building, as on the Units sheet.'),
            new ImportColumn('utility', 'Utility', required: true, aliases: ['utility type', 'utility name', 'charge', 'service', 'utility code'], example: 'Security levy', help: 'The utility\'s name or code. One you do not have yet is added to your utilities.'),
            new ImportColumn('metered', 'Metered', aliases: ['metered?', 'is metered', 'meter', 'charging', 'by meter'], example: 'No', help: 'Yes: charged by meter reading (a rate per unit). No: a flat amount each cycle. Empty: as the utility charges (No for a new one).', options: ['Yes', 'No']),
            new ImportColumn('amount', 'Amount', aliases: ['rate', 'price', 'charge amount', 'amount per cycle', 'rate per unit', 'tariff', 'fee'], example: '₦10,000', help: 'Flat: the amount each billing cycle. Metered: the rate per unit of measure. Empty: the utility\'s own amount (required for a new utility).'),
            new ImportColumn('billing_cycle', 'Billing cycle', aliases: ['cycle', 'frequency', 'payment cycle', 'billing frequency'], example: BillingCycle::Monthly->label(), help: 'Empty: the utility\'s own cycle (required for a new utility).', options: array_map(static fn (BillingCycle $cycle): string => $cycle->label(), BillingCycle::cases())),
            new ImportColumn('unit_of_measure', 'Unit of measure', aliases: ['unit of measurement', 'measure', 'uom', 'meter unit'], help: 'For a new metered utility: what its meter counts.', options: array_map(static fn (MeterUnit $unit): string => self::unitOption($unit), MeterUnit::cases())),
            new ImportColumn('last_reading', 'Last meter reading', aliases: ['meter reading', 'last reading', 'current reading', 'reading', 'opening reading', 'starting reading'], help: 'Metered only: the meter\'s reading now (up to three decimals). Consumption after it is billed.'),
            new ImportColumn('paid_until', 'Paid until', aliases: ['paid to', 'paid up to', 'paid till', 'paid through'], help: 'The last day the tenant has already paid this utility for. GoProperty bills from the period after it (empty: from the import day) and never bills past periods.'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = $this->refIssues($row, $run);
        $unit = $this->unitOf($row, $run);

        if ($row->text('building') === null) {
            $issues[] = RowIssue::error('building', __('Enter the unit\'s building: its name or Your ID.'));
        }

        if ($row->text('unit_label') === null) {
            $issues[] = RowIssue::error('unit_label', __('Enter the unit\'s name, such as Flat 3B.'));
        } elseif ($unit?->error !== null) {
            $issues[] = RowIssue::error($unit->aboutBuilding ? 'building' : 'unit_label', $unit->error);
        }

        $name = $this->nameCell($row, $issues);
        $metered = $this->yesNoCell($row, 'metered', $issues);
        $amount = $this->amountCell($row, $issues);
        $cycle = $this->cycleCell($row, $issues);
        $measure = $this->measureCell($row, $issues);
        $reading = $this->readingCell($row, $issues);
        $paidUntil = $this->dateCell($row, 'paid_until', $issues);

        if (self::hasErrors($issues) || $unit === null || $name === null) {
            return $issues;
        }

        $type = $this->type($name);
        $planned = $this->newTypes[$run][self::nameKey($name)] ?? null;

        if ($type !== null) {
            $issues = [...$issues, ...$this->existingTypeIssues($type, $metered, $measure)];
            [$isMetered, $defaultAmount, $defaultCycle] = [$type->isMetered(), $type->default_amount_kobo, $type->default_cycle];
        } elseif ($planned !== null) {
            if ($metered !== null && $metered !== $planned['metered']) {
                $issues[] = RowIssue::error('metered', __('Row :row adds :utility as :charging. Use the same on every row.', ['row' => $planned['row'], 'utility' => $name, 'charging' => $planned['metered'] ? __('metered') : __('flat')]));
            } elseif ($planned['metered'] && $measure !== null && $measure !== $planned['unit']) {
                $issues[] = RowIssue::error('unit_of_measure', __('Row :row adds :utility counted in :unit. Use the same on every row.', ['row' => $planned['row'], 'utility' => $name, 'unit' => self::unitOption($planned['unit'] ?? MeterUnit::Unit)]));
            }

            [$isMetered, $defaultAmount, $defaultCycle] = [$planned['metered'], $planned['amount'], $planned['cycle']];
        } else {
            $isMetered = $metered ?? false;

            if ($amount === null) {
                $issues[] = RowIssue::error('amount', __(':utility is new: enter its amount (the rate per unit when metered).', ['utility' => $name]));
            }

            if ($cycle === null) {
                $issues[] = RowIssue::error('billing_cycle', __(':utility is new: choose how often it is billed.', ['utility' => $name]));
            }

            if ($isMetered && $measure === null) {
                $issues[] = RowIssue::error('unit_of_measure', __(':utility is new and metered: choose what its meter counts.', ['utility' => $name]));
            }

            if (self::hasErrors($issues)) {
                return $issues;
            }

            assert($amount !== null && $cycle !== null);
            [$defaultAmount, $defaultCycle] = [$amount, $cycle];
            $this->rememberType($run, $name, $isMetered, $isMetered ? $measure : null, $amount, $cycle, $row->number);
            $issues[] = RowIssue::warning('utility', __('Adds the utility :utility (:charging) to your utilities.', [
                'utility' => $name,
                'charging' => $isMetered
                    ? __(':amount per :unit, :cycle', ['amount' => $amount->format(), 'unit' => __(($measure ?? MeterUnit::Unit)->label()), 'cycle' => mb_strtolower(__($cycle->label()))])
                    : __('flat, :amount :cycle', ['amount' => $amount->format(), 'cycle' => mb_strtolower(__($cycle->label()))]),
            ]));
        }

        if (self::hasErrors($issues)) {
            return $issues;
        }

        if ($isMetered && $reading === null) {
            $issues[] = RowIssue::error('last_reading', __('Enter the meter\'s last reading: consumption after it is billed.'));
        } elseif (! $isMetered && $reading !== null) {
            $issues[] = RowIssue::warning('last_reading', __(':utility is a flat charge: the meter reading is not used.', ['utility' => $name]));
        }

        if (! $isMetered && $measure !== null) {
            $issues[] = RowIssue::warning('unit_of_measure', __(':utility is a flat charge: the unit of measure is not used.', ['utility' => $name]));
        }

        if (self::hasErrors($issues)) {
            return $issues;
        }

        $typeKey = $type !== null ? 'id:'.$type->id : 'new:'.self::nameKey($name);

        if (($earlier = $run->rowOf(self::ROWS_BY_UNIT, $unit->key.'|'.$typeKey)) !== null) {
            return [...$issues, RowIssue::error('utility', __(':unit already has :utility on row :row. Each unit pays a utility once.', ['unit' => $unit->name, 'utility' => $name, 'row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_UNIT, $unit->key.'|'.$typeKey, $row->number);

        $match = $this->match($row, $unit, $type);
        $effectiveCycle = $cycle ?? $defaultCycle;
        $issues = [...$issues, ...$this->matchIssues($row, $match, $unit, $type, $amount, $cycle, $defaultAmount, $defaultCycle, $isMetered ? $reading : null)];

        if (! self::hasErrors($issues) && ($match === null || ! app(UnitUtilitySettings::class)->billedOrRead($match))) {
            [$from, $next] = $this->dates($paidUntil, $effectiveCycle);

            if ($next->greaterThan($from)) {
                $issues[] = RowIssue::warning('paid_until', __(':from to :to is not paid, by Paid until, and GoProperty never bills the past: put what the tenant owes for it on the Opening balances sheet. Billing starts on :first.', [
                    'from' => $from->format('d/m/Y'),
                    'to' => $this->calculator->daysAfter($next, -1)->format('d/m/Y'),
                    'first' => $next->format('d/m/Y'),
                ]));
            }
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $unit = $this->unitOf($row, $run);
        $name = (string) $row->text('utility');
        $type = $this->type($name);
        $planned = $this->newTypes[$run][self::nameKey($name)] ?? null;
        $isMetered = $type?->isMetered() ?? $planned['metered'] ?? false;
        $cycle = $this->cycleOf($row) ?? $type->default_cycle ?? $planned['cycle'] ?? BillingCycle::Monthly;
        $amount = $row->amountKobo('amount');
        $amount = $amount !== null ? Money::kobo($amount) : ($type->default_amount_kobo ?? $planned['amount'] ?? Money::zero());
        $match = $unit !== null ? $this->match($row, $unit, $type) : null;
        $billed = $match !== null && app(UnitUtilitySettings::class)->billedOrRead($match);
        [, $next] = $this->dates($row->date('paid_until'), $match !== null && $billed ? $match->billingCycle() : $cycle);
        $measure = $type->unit_of_measure ?? $planned['unit'] ?? $this->measureOf($row) ?? MeterUnit::Unit;

        return new RowPlan(
            action: match (true) {
                $match === null => ImportAction::Create,
                ! $match->active => ImportAction::Restore,
                default => ImportAction::Update,
            },
            preview: [
                'unit_label' => $unit?->name,
                'utility' => $type->name ?? $name,
                'metered' => $isMetered ? __('Metered') : __('Flat'),
                'amount' => $isMetered ? __(':amount per :unit', ['amount' => $amount->format(), 'unit' => __($measure->label())]) : $amount->format(),
                'billing_cycle' => $cycle->label(),
                'last_reading' => $isMetered ? MeterValue::parse((string) $row->text('last_reading'))?->format() : null,
                'Billed from' => $billed ? $match->next_billing_date?->format('d/m/Y') : $next->format('d/m/Y'), // not a column: shown under this heading
            ],
            yearlyCollectionsKobo: $isMetered ? 0 : self::yearly($amount, $cycle)->kobo,
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $unit = $this->unitOf($row, $run)?->unit;

        if ($unit === null) {
            return new UpsertResult(ImportAction::Skip, reason: __('The unit is no longer on file.'));
        }

        $name = (string) $row->text('utility');
        $types = app(UtilityTypes::class);
        $type = $this->type($name);
        $amount = ($kobo = $row->amountKobo('amount')) !== null ? Money::kobo($kobo) : null;
        $cycle = $this->cycleOf($row);

        if ($type === null) {
            $metered = $this->yesNoOf($row, 'metered') ?? false;
            assert($amount !== null && $cycle !== null);
            $type = $types->addForImport($run->batchId(), $name, $metered ? ChargingMode::Metered : ChargingMode::Flat, $metered ? $this->measureOf($row) : null, $amount, $cycle);
        } elseif (! $type->active) {
            $type = $types->restoreForImport($type, $run->batchId());
        }

        $match = $this->match($row, new UnitReference($unit, false, 'id:'.$unit->id, $unit->label, null), $type);
        $effectiveCycle = $cycle ?? $type->default_cycle;
        [$from, $next] = $this->dates($row->date('paid_until'), $effectiveCycle);

        try {
            [$action, $setting] = app(UnitUtilitySettings::class)->import($run->batchId(), new ImportedUtilitySetting(
                propertyId: $unit->propertyId,
                unitId: $unit->id,
                utilityTypeId: $type->id,
                amount: $amount !== null && ! $amount->equals($type->default_amount_kobo) ? $amount : null,
                cycle: $cycle !== null && $cycle !== $type->default_cycle ? $cycle : null,
                effectiveFrom: $from,
                nextBillingDate: $next,
                startingReading: $type->isMetered() ? MeterValue::parse((string) $row->text('last_reading')) : null,
                externalRef: $row->externalRef(),
            ), $match);
        } catch (ValidationException $refused) {
            return new UpsertResult(ImportAction::Skip, reason: (string) collect($refused->errors())->flatten()->first());
        }

        return new UpsertResult($action, $setting->id);
    }

    public function rollback(int $batchId): int
    {
        return app(UnitUtilitySettings::class)->rollBackImport($batchId) + app(UtilityTypes::class)->rollBackImport($batchId);
    }

    /**
     * Used once a setting of the batch has a bill or a meter reading, or a
     * utility the batch added is paid by a unit outside the batch.
     */
    public function usedSince(int $batchId): bool
    {
        $settings = UnitUtilitySetting::query()->where('import_batch_id', $batchId)->select('id');
        $types = UtilityType::query()->where('import_batch_id', $batchId)->select('id');

        return Bill::query()->withTrashed()->whereIn('unit_utility_setting_id', $settings)->exists()
            || UtilityReading::query()->withTrashed()->whereIn('unit_utility_setting_id', (clone $settings))->exists()
            || UnitUtilitySetting::query()->whereIn('utility_type_id', $types)
                ->where(static fn ($query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
                ->exists();
    }

    /**
     * The date the utility applies from (the day after "Paid until", else
     * the import day) and the first period to bill: never one that started
     * before the import day (no back-billing).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dates(?CarbonImmutable $paidUntil, BillingCycle $cycle): array
    {
        $today = BillDates::today();
        $from = $paidUntil !== null ? $this->calculator->daysAfter($paidUntil, 1) : $today;
        $next = $from->greaterThanOrEqualTo($today) ? $from : $this->calculator->nextPeriodStart($from, $cycle, $this->calculator->daysAfter($today, -1));

        return [$from, $next];
    }

    /** @return list<RowIssue> */
    private function existingTypeIssues(UtilityType $type, ?bool $metered, ?MeterUnit $measure): array
    {
        if (! $type->active && $type->import_batch_id === null) {
            return [RowIssue::error('utility', __(':utility is archived. Restore it on the Utilities page before importing it.', ['utility' => $type->name]))];
        }

        $issues = [];

        if ($metered !== null && $metered !== $type->isMetered()) {
            $issues[] = RowIssue::error('metered', $type->isMetered()
                ? __(':utility is metered on GoProperty. Leave Metered empty or put Yes.', ['utility' => $type->name])
                : __(':utility is a flat charge on GoProperty. Leave Metered empty or put No.', ['utility' => $type->name]));
        } elseif ($type->isMetered() && $measure !== null && $measure !== $type->unit_of_measure) {
            $issues[] = RowIssue::error('unit_of_measure', __(':utility is counted in :unit on GoProperty.', ['utility' => $type->name, 'unit' => self::unitOption($type->unit_of_measure ?? MeterUnit::Unit)]));
        }

        if (! $type->active) {
            $issues[] = RowIssue::warning('utility', __(':utility was archived when an import was rolled back. Importing brings it back.', ['utility' => $type->name]));
        }

        return $issues;
    }

    /**
     * Checks against the setting the row matched: the same unit and
     * utility; no new terms once it has a bill or a reading.
     *
     * @return list<RowIssue>
     */
    private function matchIssues(ImportRow $row, ?UnitUtilitySetting $match, UnitReference $unit, ?UtilityType $type, ?Money $amount, ?BillingCycle $cycle, Money $defaultAmount, BillingCycle $defaultCycle, ?MeterValue $reading): array
    {
        if ($match === null) {
            return [];
        }

        if ($unit->key !== 'id:'.$match->unit_id || $type === null || $type->id !== $match->utility_type_id) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is another unit\'s or utility\'s on GoProperty. Check the ID.'))];
        }

        $billed = app(UnitUtilitySettings::class)->billedOrRead($match);

        if (! $match->active) {
            return $billed
                ? [RowIssue::error(null, __(':utility of :unit was billed or read before it was switched off. Switch it on on its page.', ['utility' => $type->name, 'unit' => $unit->name]))]
                : [RowIssue::warning(null, __(':utility of :unit is switched off on GoProperty. Importing switches it on again.', ['utility' => $type->name, 'unit' => $unit->name]))];
        }

        if (! $billed) {
            return [];
        }

        $wantedAmount = $amount ?? $defaultAmount;
        $wantedCycle = $cycle ?? $defaultCycle;
        $changed = ! $wantedAmount->equals($match->amount())
            || $wantedCycle !== $match->billingCycle()
            || ($reading !== null && ! $reading->equals($match->startingReading()));

        if ($changed) {
            return [RowIssue::error(null, __(':utility of :unit has bills or meter readings already, so an import cannot change it. Change it on its page.', ['utility' => $type->name, 'unit' => $unit->name]))];
        }

        return $row->text('paid_until') !== null
            ? [RowIssue::warning('paid_until', __(':utility of :unit is billed already: billing continues from :date, whatever Paid until says.', ['utility' => $type->name, 'unit' => $unit->name, 'date' => $match->next_billing_date?->format('d/m/Y') ?? $match->effective_from->format('d/m/Y')]))]
            : [];
    }

    /** The setting the row is about: by Your ID, then by unit + utility. */
    private function match(ImportRow $row, UnitReference $unit, ?UtilityType $type): ?UnitUtilitySetting
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($found = UnitUtilitySetting::query()->with('utilityType')->where('external_ref', $ref)->first()) !== null) {
            return $found;
        }

        // By the id behind the key: a unit the file brings back after a rollback is known by its id too.
        $unitId = $unit->key !== null && str_starts_with($unit->key, 'id:') ? (int) substr($unit->key, 3) : null;

        if ($unitId === null || $type === null) {
            return null;
        }

        return UnitUtilitySetting::query()->with('utilityType')->where('unit_id', $unitId)->where('utility_type_id', $type->id)->first();
    }

    /** The organization's utility type by code or name (archived ones included), without case. */
    private function type(string $name): ?UtilityType
    {
        $name = self::collapsed($name);

        return UtilityType::query()
            ->where(static fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orWhereRaw('LOWER(code) = ?', [mb_strtolower($name)]))
            ->orderByRaw('LOWER(name) = ? DESC', [mb_strtolower($name)])
            ->orderBy('id')
            ->first();
    }

    private function rememberType(ImportRun $run, string $name, bool $metered, ?MeterUnit $unit, Money $amount, BillingCycle $cycle, int $row): void
    {
        if (! $run->dryRun) {
            return;
        }

        $known = $this->newTypes[$run] ?? [];
        $known[self::nameKey($name)] ??= ['metered' => $metered, 'unit' => $unit, 'amount' => $amount, 'cycle' => $cycle, 'row' => $row];
        $this->newTypes[$run] = $known;
    }

    private function unitOf(ImportRow $row, ImportRun $run): ?UnitReference
    {
        $building = $row->text('building');
        $label = $row->text('unit_label');

        if ($building === null || $label === null) {
            return null;
        }

        $key = mb_strtolower($building)."\n".mb_strtolower($label);
        $known = $this->unitRefs[$run] ?? [];

        if (! isset($known[$key])) {
            $known[$key] = app(ImportedUnits::class)->resolve($building, $label, $run);
            $this->unitRefs[$run] = $known;
        }

        return $known[$key];
    }

    /** @return list<RowIssue> */
    private function refIssues(ImportRow $row, ImportRun $run): array
    {
        $ref = $row->externalRef();

        if ($ref === null) {
            return [];
        }

        if (mb_strlen($ref) > self::MAX_REF) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('Use at most :max characters for your ID.', ['max' => self::MAX_REF]))];
        }

        if (($earlier = $run->rowOf(self::ROWS_BY_REF, $ref)) !== null) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each unit\'s utility goes on one row.', ['row' => $earlier]))];
        }

        $run->remember(self::ROWS_BY_REF, $ref, $row->number);

        return [];
    }

    /** @param  list<RowIssue>  $issues */
    private function nameCell(ImportRow $row, array &$issues): ?string
    {
        $name = $row->text('utility');

        if ($name === null) {
            $issues[] = RowIssue::error('utility', __('Enter the utility, such as Security levy or Electricity.'));

            return null;
        }

        $name = self::collapsed($name);

        if (preg_match('/[<>]/', $name) === 1) {
            $issues[] = RowIssue::error('utility', __('Use plain text only (no < or >).'));

            return null;
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > self::MAX_NAME) {
            $issues[] = RowIssue::error('utility', __('Use 2 to :max characters for the utility\'s name.', ['max' => self::MAX_NAME]));

            return null;
        }

        return $name;
    }

    /** @param  list<RowIssue>  $issues */
    private function yesNoCell(ImportRow $row, string $column, array &$issues): ?bool
    {
        $text = $row->text($column);

        if ($text === null) {
            return null;
        }

        $value = self::yesNo($text);

        if ($value === null) {
            $issues[] = RowIssue::error($column, __('Enter Yes or No.'));
        }

        return $value;
    }

    /** @param  list<RowIssue>  $issues */
    private function amountCell(ImportRow $row, array &$issues): ?Money
    {
        try {
            $kobo = $row->amountKobo('amount');
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error('amount', $invalid->getMessage());

            return null;
        }

        if ($kobo === null) {
            return null;
        }

        if ($kobo <= 0) {
            $issues[] = RowIssue::error('amount', __('Enter an amount above ₦0.'));

            return null;
        }

        if ($kobo >= Money::naira(self::MAX_NAIRA)->kobo) {
            $issues[] = RowIssue::error('amount', __('Enter an amount below ₦10,000,000,000.'));

            return null;
        }

        return Money::kobo($kobo);
    }

    /** @param  list<RowIssue>  $issues */
    private function cycleCell(ImportRow $row, array &$issues): ?BillingCycle
    {
        $text = $row->text('billing_cycle');
        $cycle = $this->cycleOf($row);

        if ($text !== null && $cycle === null) {
            $issues[] = RowIssue::error('billing_cycle', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (BillingCycle $c): string => $c->label(), BillingCycle::cases()))]));
        }

        return $cycle;
    }

    /** @param  list<RowIssue>  $issues */
    private function measureCell(ImportRow $row, array &$issues): ?MeterUnit
    {
        $text = $row->text('unit_of_measure');
        $unit = $this->measureOf($row);

        if ($text !== null && $unit === null) {
            $issues[] = RowIssue::error('unit_of_measure', __('Choose one of: :options.', ['options' => implode(', ', array_map(static fn (MeterUnit $u): string => self::unitOption($u), MeterUnit::cases()))]));
        }

        return $unit;
    }

    /** @param  list<RowIssue>  $issues */
    private function readingCell(ImportRow $row, array &$issues): ?MeterValue
    {
        $text = $row->text('last_reading');

        if ($text === null) {
            return null;
        }

        $value = MeterValue::parse(str_replace(',', '', $text));

        if ($value === null) {
            $issues[] = RowIssue::error('last_reading', __('Enter the meter reading as it shows, such as 12345.6 (up to three decimals).'));
        }

        return $value;
    }

    /** @param  list<RowIssue>  $issues */
    private function dateCell(ImportRow $row, string $column, array &$issues): ?CarbonImmutable
    {
        try {
            return $row->date($column);
        } catch (InvalidCellValue $invalid) {
            $issues[] = RowIssue::error($column, $invalid->getMessage());

            return null;
        }
    }

    private function cycleOf(ImportRow $row): ?BillingCycle
    {
        $text = $row->text('billing_cycle');

        return $text !== null ? BillingCycle::fromText($text) : null;
    }

    private function measureOf(ImportRow $row): ?MeterUnit
    {
        $text = $row->text('unit_of_measure');

        if ($text === null) {
            return null;
        }

        $key = (string) preg_replace('/[^a-z0-9³]/u', '', mb_strtolower($text));

        foreach (MeterUnit::cases() as $unit) {
            $names = [$unit->value, $unit->label(), $unit->optionLabel(), self::unitOption($unit)];

            foreach ($names as $known) {
                if ($key === preg_replace('/[^a-z0-9³]/u', '', mb_strtolower($known))) {
                    return $unit;
                }
            }
        }

        return match ($key) {
            'kilowatthour', 'kilowatthours', 'kw', 'kwhs' => MeterUnit::Kwh,
            'm3', 'cubicmetre', 'cubicmeter', 'cubicmeters', 'cubicmetres' => MeterUnit::CubicMetre,
            'l', 'litre', 'liter', 'liters', 'litres', 'ltr' => MeterUnit::Litre,
            'unit', 'units' => MeterUnit::Unit,
            default => null,
        };
    }

    private function yesNoOf(ImportRow $row, string $column): ?bool
    {
        $text = $row->text($column);

        return $text !== null ? self::yesNo($text) : null;
    }

    /** The dropdown's word for a unit of measure. */
    private static function unitOption(MeterUnit $unit): string
    {
        return match ($unit) {
            MeterUnit::Kwh => 'kWh',
            MeterUnit::CubicMetre => 'Cubic metres',
            MeterUnit::Litre => 'Litres',
            MeterUnit::Unit => 'Units',
        };
    }

    private static function yesNo(string $text): ?bool
    {
        return match (mb_strtolower(trim($text))) {
            'yes', 'y', 'true', '1', 'metered' => true,
            'no', 'n', 'false', '0', 'flat' => false,
            default => null,
        };
    }

    private static function collapsed(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(self::collapsed($name));
    }

    /** Expected yearly collections of a flat utility, for the Transaction Tier forecast (DATA_IMPORT.md § 2.3). */
    private static function yearly(Money $amount, BillingCycle $cycle): Money
    {
        $months = $cycle->months();

        return match (true) {
            $cycle === BillingCycle::Daily => $amount->times(365),
            $cycle === BillingCycle::Weekly => $amount->times(52),
            $months !== null && $months <= 12 => $amount->times(intdiv(12, $months)),
            default => $amount->dividedBy(2),
        };
    }

    /** @param  list<RowIssue>  $issues */
    private static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }
}
