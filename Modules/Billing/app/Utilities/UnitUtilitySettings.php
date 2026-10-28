<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * The only writer of units' utility settings (DATABASE_SCHEMA.md § 4
 * `unit_utility_settings`; P03-06a): add a utility to units of a building
 * (one or all of them at once) and change one unit's setting — switch it
 * on or off, override the type's amount and cycle (empty: the type's
 * default), the date it applies from and a metered utility's starting
 * reading. Checked against UnitUtilitySettingPolicy for the acting member
 * (so the suspension and impersonation guards apply) and the building
 * against their narrowing (Modules\Properties\Contracts\BuildingAccess);
 * audited under log `utilities`, the setting as subject.
 *
 * One setting per unit and type (unique `(unit_id, utility_type_id)`):
 * units that have the utility already are skipped and named. Units and
 * buildings are read through the Properties module's contracts only. The
 * starting reading changes only while the meter has no kept reading (the
 * readings after it count from it). A utility switched on again bills from
 * the first period starting on or after that day (P03-06b): the periods it
 * was off are never billed.
 */
final class UnitUtilitySettings
{
    private const array AUDITED = ['amount_kobo', 'cycle', 'active', 'effective_from', 'starting_reading'];

    public function __construct(
        private readonly PropertyDirectory $buildings,
        private readonly UnitDirectory $units,
        private readonly BuildingAccess $access,
        private readonly CycleCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $input  building (id), utilityType (id), units (list of unit ids), amount, cycle, effectiveFrom, startingReading
     *
     * @throws ValidationException
     */
    public function add(User $by, array $input): AddedSettings
    {
        Gate::forUser($by)->authorize('create', UnitUtilitySetting::class);

        $buildingId = self::id($input['building'] ?? null);

        if ($buildingId !== null && ! $this->access->allows($by, $buildingId)) {
            Response::denyAsNotFound()->authorize();
        }

        $type = ($typeId = self::id($input['utilityType'] ?? null)) !== null ? UtilityType::query()->find($typeId) : null;
        $offered = $buildingId !== null && $this->buildings->isActive($buildingId) ? $this->units->inProperty($buildingId) : [];
        $unitIds = array_values(array_unique(array_filter(array_map(self::id(...), is_array($input['units'] ?? null) ? $input['units'] : []))));

        $values = [
            'building' => $buildingId !== null && $this->buildings->isActive($buildingId) ? $buildingId : null,
            'utilityType' => $type instanceof UtilityType && $type->active ? $type->id : null,
            'units' => $unitIds,
            ...$this->terms($input),
        ];

        Validator::make($values, [
            'building' => ['required'],
            'utilityType' => ['required'],
            'units' => ['required', 'array', 'min:1', static function (string $attribute, mixed $value, Closure $fail) use ($offered): void {
                if (is_array($value) && array_diff($value, array_keys($offered)) !== []) {
                    $fail(__('Choose units of this building.'));
                }
            }],
            ...$this->termRules($type, true),
        ], [
            'building.required' => __('Choose an active building.'),
            'utilityType.required' => __('Choose a utility that is not archived.'),
            'units.required' => __('Choose at least one unit.'),
        ])->validate();

        assert($type instanceof UtilityType && $buildingId !== null);

        try {
            return DB::transaction(function () use ($by, $type, $buildingId, $unitIds, $offered, $values): AddedSettings {
                $existing = UnitUtilitySetting::query()->where('utility_type_id', $type->id)->whereIn('unit_id', $unitIds)->pluck('unit_id')->map(fn (mixed $id): int => (int) $id)->all();
                $created = [];
                $skipped = [];

                foreach ($unitIds as $unitId) {
                    if (in_array($unitId, $existing, true)) {
                        $skipped[] = $offered[$unitId]->label;

                        continue;
                    }

                    $setting = new UnitUtilitySetting;
                    $setting->forceFill([
                        'property_id' => $buildingId,
                        'unit_id' => $unitId,
                        'utility_type_id' => $type->id,
                        ...$this->columns($values, $type),
                    ])->save();
                    $setting->setRelation('utilityType', $type);
                    $this->audit('utility_setting.added', $setting, $by, 'Utility added to a unit', ['unit' => $offered[$unitId]->label]);
                    $created[] = $setting;
                }

                return new AddedSettings($created, $skipped);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['units' => __('Someone added this utility to one of these units at the same time. Try again.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $input  active (bool), amount, cycle, effectiveFrom, startingReading
     *
     * @throws ValidationException
     */
    public function update(User $by, UnitUtilitySetting $setting, array $input): UnitUtilitySetting
    {
        Gate::forUser($by)->authorize('update', $setting);

        $type = $setting->utilityType;
        $values = $this->terms($input);
        Validator::make($values, $this->termRules($type, false))->validate();

        return DB::transaction(function () use ($by, $setting, $type, $values, $input): UnitUtilitySetting {
            $setting = UnitUtilitySetting::query()->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();
            $setting->setRelation('utilityType', $type);
            $wasActive = $setting->active;
            $setting->forceFill([...$this->columns($values, $type), 'active' => filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN)]);

            if (! $wasActive && $setting->active) {
                // Switched on again (P03-06b): billing resumes with the first
                // period starting on or after today; the periods while it
                // was off are not billed.
                $setting->next_billing_date = $this->calculator->nextPeriodStart($setting->effective_from, $setting->billingCycle(), $this->calculator->daysAfter(BillDates::today(), -1));
                $setting->billing_hold = null;
                $setting->billing_hold_since = null;
            }

            if ($setting->isDirty('starting_reading') && UtilityReading::query()->where('unit_utility_setting_id', $setting->id)->exists()) {
                throw ValidationException::withMessages(['startingReading' => __('This meter has readings already: the starting reading stays as it is. Correct the latest reading instead.')]);
            }

            $changed = array_values(array_intersect(self::AUDITED, array_keys($setting->getDirty())));

            if ($changed !== []) {
                $setting->save();
                $this->audit('utility_setting.updated', $setting, $by, 'Unit\'s utility changed', ['changed' => $changed]);
            }

            return $setting;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{amount: string, cycle: string, effectiveFrom: string, startingReading: string}
     */
    private function terms(array $input): array
    {
        return [
            'amount' => is_string($input['amount'] ?? null) ? trim($input['amount']) : '',
            'cycle' => is_string($input['cycle'] ?? null) ? $input['cycle'] : '',
            'effectiveFrom' => is_string($input['effectiveFrom'] ?? null) ? $input['effectiveFrom'] : '',
            'startingReading' => is_string($input['startingReading'] ?? null) ? trim($input['startingReading']) : '',
        ];
    }

    /** @return array<string, list<mixed>> */
    private function termRules(?UtilityType $type, bool $adding): array
    {
        return [
            'amount' => ['nullable', static function (string $attribute, mixed $value, Closure $fail): void {
                $amount = UtilityInput::naira($value);

                if (is_string($value) && $value !== '' && ($amount === null || ! $amount->isPositive())) {
                    $fail(__('Enter an amount in naira, such as 25,000, or leave it empty for the utility\'s default.'));
                }
            }],
            'cycle' => ['nullable', Rule::in(['', ...BillingCycle::values()])],
            'effectiveFrom' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (UtilityInput::date($value) === null) {
                    $fail(__('Enter a date.'));
                }
            }],
            'startingReading' => ['nullable', static function (string $attribute, mixed $value, Closure $fail) use ($type): void {
                if (is_string($value) && $value !== '' && $type?->isMetered() === true && MeterValue::parse($value) === null) {
                    $fail(__('Enter the meter reading as it shows, such as 12345.6 (up to three decimals).'));
                }
            }],
        ];
    }

    /**
     * @param  array<string, mixed>  $values  from terms()
     * @return array<string, mixed>
     */
    private function columns(array $values, UtilityType $type): array
    {
        $date = UtilityInput::date($values['effectiveFrom']);
        assert($date instanceof CarbonImmutable);

        return [
            'amount_kobo' => UtilityInput::naira($values['amount']),
            'cycle' => BillingCycle::tryFrom((string) $values['cycle']),
            'effective_from' => $date,
            'starting_reading' => $type->isMetered() ? MeterValue::parse((string) $values['startingReading']) : null,
        ];
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : (is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, UnitUtilitySetting $setting, User $by, string $description, array $properties = []): void
    {
        activity(UtilityTypes::LOG)
            ->event($event)
            ->performedOn($setting)
            ->causedBy($by)
            ->withProperties(['utility' => $setting->utilityType->name, ...$properties])
            ->log($description);
    }
}
