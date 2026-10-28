<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\MeterReadings;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Billing\Utilities\UtilityTypes;
use Modules\Properties\Models\Unit;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the utility tests (P03-06a). Types, settings and readings are
 * made through the real writers (Utilities\UtilityTypes,
 * UnitUtilitySettings, MeterReadings) as the given member; pages through
 * HTTP and Livewire updates. Buildings, units and assignments come from the
 * Properties module's helpers (through the Billing helpers).
 */

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/media.php';

/** A utility type made through the writer: flat ₦25,000 monthly unless changed. */
function utilityTypeIn(Organization $organization, User $by, array $changes = []): UtilityType
{
    return inOrganization($organization, fn (): UtilityType => app(UtilityTypes::class)->create($by, [
        'name' => 'Security levy',
        'code' => 'SECURITY',
        'chargingMode' => 'flat',
        'unitOfMeasure' => '',
        'amount' => '25,000',
        'cycle' => 'monthly',
        ...$changes,
    ]));
}

/** A metered electricity type, ₦250 per kWh monthly, made through the writer. */
function electricityIn(Organization $organization, User $by, array $changes = []): UtilityType
{
    return utilityTypeIn($organization, $by, ['name' => 'Electricity', 'code' => 'ELEC', 'chargingMode' => 'metered', 'unitOfMeasure' => 'kwh', 'amount' => '250', ...$changes]);
}

/** The type added to the unit through the writer (from 1 Jan 2027 unless changed). */
function utilityOn(Organization $organization, User $by, Unit $unit, UtilityType $type, array $changes = []): UnitUtilitySetting
{
    return inOrganization($organization, function () use ($by, $unit, $type, $changes): UnitUtilitySetting {
        $added = app(UnitUtilitySettings::class)->add($by, [
            'building' => $unit->property_id,
            'utilityType' => $type->id,
            'units' => [$unit->id],
            'amount' => '',
            'cycle' => '',
            'effectiveFrom' => '2027-01-01',
            'startingReading' => '',
            ...$changes,
        ]);

        return $added->created[0];
    });
}

/** A meter reading recorded through the writer. */
function readMeter(Organization $organization, User $by, UnitUtilitySetting $setting, string $date, string $value, array $changes = [], ?UploadedFile $photo = null): UtilityReading
{
    return inOrganization($organization, fn (): UtilityReading => app(MeterReadings::class)->record(
        $by,
        storedSetting($organization, $setting),
        ['readingDate' => $date, 'value' => $value, 'meterReplaced' => false, 'newMeterStart' => '', 'note' => '', ...$changes],
        $photo,
    ));
}

/** The setting as stored now, with its type. */
function storedSetting(Organization $organization, UnitUtilitySetting|int $setting): UnitUtilitySetting
{
    return inOrganization($organization, fn (): UnitUtilitySetting => UnitUtilitySetting::query()->with('utilityType')->findOrFail($setting instanceof UnitUtilitySetting ? $setting->id : $setting));
}

/** The type as stored now. */
function storedType(Organization $organization, UtilityType|int $type): UtilityType
{
    return inOrganization($organization, fn (): UtilityType => UtilityType::query()->findOrFail($type instanceof UtilityType ? $type->id : $type));
}

/** The reading as stored now, removed ones too. */
function storedReading(Organization $organization, UtilityReading|int $reading): UtilityReading
{
    return inOrganization($organization, fn (): UtilityReading => UtilityReading::query()->withTrashed()->findOrFail($reading instanceof UtilityReading ? $reading->id : $reading));
}

/**
 * The meter's kept readings as [date, previous, current, consumption] rows, by date.
 *
 * @return list<array{string, string, string, string}>
 */
function meterReadingsOf(Organization $organization, UnitUtilitySetting $setting): array
{
    return inOrganization($organization, fn (): array => UtilityReading::query()->where('unit_utility_setting_id', $setting->id)->orderBy('reading_date')->get()
        ->map(fn (UtilityReading $reading): array => [$reading->reading_date->toDateString(), $reading->previous_value->input(), $reading->current_value->input(), $reading->consumption->input()])
        ->all());
}

/** Every setting of the organization, oldest first. */
function settingsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => UnitUtilitySetting::query()->orderBy('id')->get());
}

/** A page of the utilities area, as the user ($path after /utilities). */
function utilitiesPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/utilities{$path}");
}

/** The Livewire snapshot of a utilities page the user can open. */
function utilitiesSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(utilitiesPage($user, $path, $slug)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function utilitiesUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The ids a rendered utilities list shows for the row attribute (utility-type, utility-setting, utility-reading), in order. */
function listedUtilityRows(TestResponse|string $page, string $attribute): array
{
    preg_match_all('/data-'.$attribute.'="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
