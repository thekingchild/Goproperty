<?php

declare(strict_types=1);

namespace Modules\Billing\Utilities;

use App\Media\MediaUploads;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Bills\BillDates;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * The only writer of meter readings (DATABASE_SCHEMA.md § 4
 * `utility_readings`, ARCHITECTURE.md § 6.4; P03-06a): record a reading of
 * a unit's metered utility (date, value, an optional photo of the meter),
 * correct the latest one or remove it while it is not billed. Checked
 * against the policies for the acting member (UnitUtilitySettingPolicy::
 * recordReading, UtilityReadingPolicy::update/delete: narrowing, the
 * suspension and impersonation guards) and audited under log `utilities`.
 *
 * The rules, checked under the meter's lock (the setting's row, `FOR
 * UPDATE`) so two members recording at once wait for each other:
 * - readings follow each other by date: a new one is dated after the
 *   latest kept reading (never on the same day: also a unique key), on or
 *   after the date the setting applies from, and never in the future
 *   (Lagos);
 * - **a reading never goes backwards**: its value is at least the previous
 *   one (the latest kept reading's value, or the setting's starting
 *   reading);
 * - **meter replaced**: a new meter starts again (usually at 0). The
 *   member ticks "meter replaced" and gives the new meter's first value;
 *   the reading then counts from that value instead of the old meter's.
 *   Record a last reading of the old meter first, so its consumption is
 *   billed;
 * - consumption = current − previous; the rate per unit of measure is
 *   the setting's at the time (its override, else the type's default),
 *   kept on the reading for P03-06b to bill.
 *
 * A billed reading never changes. Removing is a soft delete that keeps the
 * reading and its photo (evidence, AGENT.md § 3 rule 7) with who removed
 * it and why.
 */
final class MeterReadings
{
    public function __construct(
        private readonly MediaUploads $uploads,
        private readonly UnitDirectory $units,
    ) {}

    /**
     * @param  array<string, mixed>  $input  readingDate (Y-m-d), value, meterReplaced (bool), newMeterStart, note
     *
     * @throws ValidationException
     */
    public function record(User $by, UnitUtilitySetting $setting, array $input, ?UploadedFile $photo = null): UtilityReading
    {
        Gate::forUser($by)->authorize('recordReading', $setting);

        $values = $this->validated($input);

        try {
            return DB::transaction(function () use ($by, $setting, $values, $photo): UtilityReading {
                $setting = $this->lock($setting);
                Gate::forUser($by)->authorize('recordReading', $setting);

                $latest = $this->latest($setting);
                [$previous, $consumption] = $this->check($setting, $latest, $values);

                $reading = new UtilityReading;
                $reading->forceFill([
                    'unit_utility_setting_id' => $setting->id,
                    'reading_date' => $values['date'],
                    'previous_value' => $previous,
                    'current_value' => $values['value'],
                    'consumption' => $consumption,
                    'meter_replaced' => $values['replaced'],
                    'rate_kobo' => $setting->amount(),
                    'note' => $values['note'],
                    'recorded_by_user_id' => $by->id,
                ])->save();

                if ($photo !== null) {
                    $this->uploads->add($reading, UtilityReading::PHOTO_COLLECTION, $photo, null, ['utility_reading_id' => $reading->id]);
                }

                $this->audit('utility_reading.recorded', $reading, $setting, $by, 'Meter reading recorded', $this->summary($reading));

                return $reading;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['readingDate' => __('This meter has a reading on that day already.')]);
        }
    }

    /**
     * Correct the latest kept reading (not billed): its date, value, meter
     * replaced and note, under the same rules against the reading before it.
     *
     * @param  array<string, mixed>  $input  as for record()
     *
     * @throws ValidationException
     */
    public function correct(User $by, UtilityReading $reading, array $input): UtilityReading
    {
        Gate::forUser($by)->authorize('update', $reading);

        $values = $this->validated($input);

        try {
            return DB::transaction(function () use ($by, $reading, $values): UtilityReading {
                $setting = $this->lock($reading->setting);
                $reading = UtilityReading::query()->whereKey($reading->getKey())->firstOrFail();
                Gate::forUser($by)->authorize('update', $reading);

                $before = $this->summary($reading);
                [$previous, $consumption] = $this->check($setting, $this->latest($setting, except: $reading), $values);

                $reading->forceFill([
                    'reading_date' => $values['date'],
                    'previous_value' => $previous,
                    'current_value' => $values['value'],
                    'consumption' => $consumption,
                    'meter_replaced' => $values['replaced'],
                    'note' => $values['note'],
                ]);

                if ($reading->isDirty()) {
                    $reading->save();
                    $this->audit('utility_reading.corrected', $reading, $setting, $by, 'Meter reading corrected', ['before' => $before, 'after' => $this->summary($reading)]);
                }

                return $reading;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['readingDate' => __('This meter has a reading on that day already.')]);
        }
    }

    /**
     * Remove the latest kept reading (not billed), with a reason: kept as
     * removed, with its photo; the reading before it becomes the latest.
     *
     * @throws ValidationException
     */
    public function remove(User $by, UtilityReading $reading, string $reason): void
    {
        Gate::forUser($by)->authorize('delete', $reading);

        $reason = UtilityInput::text($reason);

        if ($reason === null || mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['removalReason' => __('Say why the reading is removed (3 to 255 characters).')]);
        }

        DB::transaction(function () use ($by, $reading, $reason): void {
            $setting = $this->lock($reading->setting);
            $reading = UtilityReading::query()->whereKey($reading->getKey())->firstOrFail();
            Gate::forUser($by)->authorize('delete', $reading);

            $reading->forceFill([
                'live' => null,
                'removed_by_user_id' => $by->id,
                'removal_reason' => $reason,
                'deleted_at' => CarbonImmutable::now(),
            ])->save();
            $this->audit('utility_reading.removed', $reading, $setting, $by, 'Meter reading removed', [...$this->summary($reading), 'reason' => $reason]);
        }, 3);
    }

    /** The latest kept reading of the meter (by date), or null; optionally leaving one reading out. */
    public function latest(UnitUtilitySetting $setting, ?UtilityReading $except = null): ?UtilityReading
    {
        return UtilityReading::query()
            ->where('unit_utility_setting_id', $setting->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The value the next reading counts from: the latest kept reading's
     * value, or the setting's starting reading.
     */
    public function previousValue(UnitUtilitySetting $setting): MeterValue
    {
        return $this->latest($setting)->current_value ?? $setting->startingReading();
    }

    /**
     * @param  array{date: CarbonImmutable, value: MeterValue, replaced: bool, newMeterStart: MeterValue, note: string|null}  $values
     * @return array{MeterValue, MeterValue} previous value, consumption
     *
     * @throws ValidationException
     */
    private function check(UnitUtilitySetting $setting, ?UtilityReading $latest, array $values): array
    {
        if ($this->units->find($setting->unit_id) === null) {
            throw ValidationException::withMessages(['value' => __('This unit is archived: its meter takes no more readings.')]);
        }

        if ($values['date']->lessThan($setting->effective_from)) {
            throw ValidationException::withMessages(['readingDate' => __('The utility applies to this unit from :date: date the reading on or after it.', ['date' => $setting->effective_from->format('d/m/Y')])]);
        }

        if ($latest !== null && ! $values['date']->greaterThan($latest->reading_date)) {
            throw ValidationException::withMessages(['readingDate' => __('Readings follow each other by date: the last one was taken on :date. Date this one after it.', ['date' => $latest->reading_date->format('d/m/Y')])]);
        }

        $previous = $values['replaced'] ? $values['newMeterStart'] : ($latest->current_value ?? $setting->startingReading());

        if ($values['value']->lessThan($previous)) {
            throw ValidationException::withMessages(['value' => $values['replaced']
                ? __('The reading cannot be below the new meter\'s first value (:value).', ['value' => $previous->format()])
                : __('A meter does not go backwards: the last reading was :value. If the meter was replaced, tick "Meter replaced".', ['value' => $previous->format()])]);
        }

        return [$previous, $values['value']->minus($previous)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{date: CarbonImmutable, value: MeterValue, replaced: bool, newMeterStart: MeterValue, note: string|null}
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $errors = [];
        $date = UtilityInput::date($input['readingDate'] ?? null);
        $value = MeterValue::parse(is_string($input['value'] ?? null) ? $input['value'] : null);
        $replaced = filter_var($input['meterReplaced'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $startTyped = is_string($input['newMeterStart'] ?? null) ? trim($input['newMeterStart']) : '';
        $newMeterStart = $startTyped === '' ? MeterValue::zero() : MeterValue::parse($startTyped);
        $note = UtilityInput::text($input['note'] ?? null);

        if ($date === null) {
            $errors['readingDate'] = __('Enter the date the meter was read.');
        } elseif ($date->greaterThan(BillDates::today())) {
            $errors['readingDate'] = __('A reading cannot be dated in the future.');
        }

        if ($value === null) {
            $errors['value'] = __('Enter the meter reading as it shows, such as 12345.6 (up to three decimals).');
        }

        if ($replaced && $newMeterStart === null) {
            $errors['newMeterStart'] = __('Enter the new meter\'s first value, such as 0.');
        }

        if ($note !== null && mb_strlen($note) > 255) {
            $errors['note'] = __('Keep the note under 255 characters.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        assert($date !== null && $value !== null && $newMeterStart !== null);

        return ['date' => $date, 'value' => $value, 'replaced' => $replaced, 'newMeterStart' => $newMeterStart, 'note' => $note];
    }

    private function lock(UnitUtilitySetting $setting): UnitUtilitySetting
    {
        $locked = UnitUtilitySetting::query()->with('utilityType')->whereKey($setting->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** @return array<string, string|bool> */
    private function summary(UtilityReading $reading): array
    {
        return [
            'reading_date' => $reading->reading_date->toDateString(),
            'previous_value' => $reading->previous_value->toDecimalString(),
            'current_value' => $reading->current_value->toDecimalString(),
            'consumption' => $reading->consumption->toDecimalString(),
            'meter_replaced' => $reading->meter_replaced,
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, UtilityReading $reading, UnitUtilitySetting $setting, User $by, string $description, array $properties): void
    {
        activity(UtilityTypes::LOG)
            ->event($event)
            ->performedOn($reading)
            ->causedBy($by)
            ->withProperties(['utility' => $setting->utilityType->name, 'unit_id' => $setting->unit_id, ...$properties])
            ->log($description);
    }
}
