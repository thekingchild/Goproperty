<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Media\UploadRefused;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Utilities\MeterReadings;
use Modules\Billing\Utilities\MeterValue;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Billing\Utilities\UtilityTypes;
use Modules\Leasing\Contracts\BillingCycle;

require_once __DIR__.'/utility-helpers.php';

/*
 * P03-06a: utility setup (ARCHITECTURE.md § 6.4; DATABASE_SCHEMA.md § 4):
 * utility types (flat or metered, defaults in kobo, archived not deleted),
 * which utilities each unit pays (one setting per unit and utility,
 * amount and cycle overrides, on/off, starting reading), and meter
 * readings (never backwards, one a day in date order, "meter replaced",
 * corrections and removals of the latest unbilled reading, meter photos as
 * evidence).
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2027-03-10 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->flat2 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
    $this->electricity = electricityIn($this->acme, $this->owner);
});

afterEach(fn () => billsFreshRequest());

/** The validation errors (field => first message) a writer call throws. */
function utilityErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return array_map(static fn (array $messages): string => $messages[0], $exception->errors());
    }

    return [];
}

it('adds utility types with amounts in kobo, the name and code unique in the organization, archived ones included', function () {
    $security = utilityTypeIn($this->acme, $this->owner, ['code' => 'security ']);
    $audit = AuditEntry::query()->where('event', 'utility_type.created')->where('subject_id', $security->id)->sole();

    expect($security->name)->toBe('Security levy')
        ->and($security->code)->toBe('SECURITY')
        ->and($security->charging_mode)->toBe(ChargingMode::Flat)
        ->and($security->unit_of_measure)->toBeNull()
        ->and($security->default_amount_kobo->kobo)->toBe(2_500_000)
        ->and($security->default_cycle)->toBe(BillingCycle::Monthly)
        ->and($security->active)->toBeTrue()
        ->and(storedType($this->acme, $this->electricity)->unit_of_measure)->toBe(MeterUnit::Kwh)
        ->and(storedType($this->acme, $this->electricity)->default_amount_kobo->kobo)->toBe(25_000)
        ->and($audit->log_name)->toBe('utilities')
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->properties['utility'])->toBe('Security levy');

    // Name and code are unique (case aside), archived types too.
    expect(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'SECURITY LEVY', 'code' => 'OTHER'])))->toHaveKey('name')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Night guard', 'code' => 'Security'])))->toHaveKey('code');
    inOrganization($this->acme, fn () => app(UtilityTypes::class)->archive($this->owner, $security));
    expect(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['code' => 'GUARD'])))->toBe(['name' => 'A utility with this name exists already (archived ones count: restore it instead).']);

    // What a type needs.
    expect(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'chargingMode' => 'metered', 'unitOfMeasure' => ''])))->toHaveKey('unitOfMeasure')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'amount' => '0'])))->toHaveKey('amount')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'amount' => '-500'])))->toHaveKey('amount')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'amount' => '12.345'])))->toHaveKey('amount')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WA TER'])))->toHaveKey('code')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WATER', 'cycle' => 'fortnightly'])))->toHaveKey('cycle')
        ->and(utilityErrors(fn () => utilityTypeIn($this->acme, $this->owner, ['name' => '<b>Water</b>', 'code' => 'WATER'])))->toHaveKey('name');

    $water = utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'water', 'chargingMode' => 'metered', 'unitOfMeasure' => 'cubic_metre', 'amount' => '₦1,250.50', 'cycle' => 'quarterly']);
    expect($water->default_amount_kobo->kobo)->toBe(125_050)
        ->and($water->default_cycle)->toBe(BillingCycle::Quarterly)
        ->and($water->unit_of_measure)->toBe(MeterUnit::CubicMetre);

    // Another organization keeps its own names and codes.
    expect(utilityTypeIn($this->other, $this->otherOwner)->code)->toBe('SECURITY');
});

it('edits a type, keeps how it charges once units use it, and archives and restores it, never deleting it', function () {
    $security = utilityTypeIn($this->acme, $this->owner);
    $types = app(UtilityTypes::class);

    inOrganization($this->acme, fn () => $types->update($this->owner, $security, ['name' => 'Security levy', 'code' => 'SECURITY', 'chargingMode' => 'flat', 'unitOfMeasure' => '', 'amount' => '30,000', 'cycle' => 'quarterly']));
    $updated = AuditEntry::query()->where('event', 'utility_type.updated')->sole();
    expect(storedType($this->acme, $security)->default_amount_kobo->kobo)->toBe(3_000_000)
        ->and(storedType($this->acme, $security)->default_cycle)->toBe(BillingCycle::Quarterly)
        ->and($updated->properties['changed'])->toEqualCanonicalizing(['default_amount_kobo', 'default_cycle']);

    // Nothing changed: no audit entry.
    inOrganization($this->acme, fn () => $types->update($this->owner, storedType($this->acme, $security), ['name' => 'Security levy', 'code' => 'SECURITY', 'chargingMode' => 'flat', 'unitOfMeasure' => '', 'amount' => '30,000.00', 'cycle' => 'quarterly']));
    expect(AuditEntry::query()->where('event', 'utility_type.updated')->count())->toBe(1);

    // Once a unit pays it, its charging stays; the rest may change.
    utilityOn($this->acme, $this->owner, $this->flat1, $security);
    expect(utilityErrors(fn () => inOrganization($this->acme, fn () => $types->update($this->owner, storedType($this->acme, $security), ['name' => 'Security levy', 'code' => 'SECURITY', 'chargingMode' => 'metered', 'unitOfMeasure' => 'kwh', 'amount' => '30,000', 'cycle' => 'quarterly']))))
        ->toBe(['chargingMode' => 'Units use this utility, so how it charges stays as it is. Add a new utility instead.'])
        ->and(storedType($this->acme, $security)->charging_mode)->toBe(ChargingMode::Flat);
    inOrganization($this->acme, fn () => $types->update($this->owner, storedType($this->acme, $security), ['name' => 'Estate security', 'code' => 'SECURITY', 'chargingMode' => 'flat', 'unitOfMeasure' => '', 'amount' => '35,000', 'cycle' => 'monthly']));
    expect(storedType($this->acme, $security)->name)->toBe('Estate security');

    // Archive: the units' settings stay; it is offered to no more units and edited no more until restored.
    inOrganization($this->acme, fn () => $types->archive($this->owner, storedType($this->acme, $security)));
    expect(storedType($this->acme, $security)->active)->toBeFalse()
        ->and(settingsOf($this->acme)->pluck('utility_type_id')->all())->toBe([$security->id])
        ->and(utilityErrors(fn () => utilityOn($this->acme, $this->owner, $this->flat2, storedType($this->acme, $security))))->toBe(['utilityType' => 'Choose a utility that is not archived.'])
        ->and(fn () => inOrganization($this->acme, fn () => $types->update($this->owner, storedType($this->acme, $security), ['name' => 'X', 'code' => 'X', 'chargingMode' => 'flat', 'unitOfMeasure' => '', 'amount' => '1', 'cycle' => 'monthly'])))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $types->archive($this->owner, storedType($this->acme, $security))))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => $types->restore($this->owner, storedType($this->acme, $security)));
    expect(storedType($this->acme, $security)->active)->toBeTrue()
        ->and(AuditEntry::query()->whereIn('event', ['utility_type.archived', 'utility_type.restored'])->orderBy('id')->pluck('event')->all())->toBe(['utility_type.archived', 'utility_type.restored'])
        ->and(fn () => inOrganization($this->acme, fn () => storedType($this->acme, $security)->delete()))->toThrow(LogicException::class);
});

it('acceptance: keeps one setting per unit and utility: adding it again skips the unit, and the database refuses a second row', function () {
    $flat3 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3']);
    $settings = app(UnitUtilitySettings::class);
    $input = ['building' => $this->palm->id, 'utilityType' => $this->electricity->id, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => ''];

    $first = inOrganization($this->acme, fn () => $settings->add($this->owner, [...$input, 'units' => [$this->flat1->id, (string) $this->flat2->id]]));
    $again = inOrganization($this->acme, fn () => $settings->add($this->owner, [...$input, 'units' => [$this->flat1->id, $flat3->id], 'amount' => '300']));

    expect(array_map(fn (UnitUtilitySetting $setting): int => $setting->unit_id, $first->created))->toBe([$this->flat1->id, $this->flat2->id])
        ->and($first->skipped)->toBe([])
        ->and(array_map(fn (UnitUtilitySetting $setting): int => $setting->unit_id, $again->created))->toBe([$flat3->id])
        ->and($again->skipped)->toBe(['Flat 1'])
        ->and(settingsOf($this->acme)->where('unit_id', $this->flat1->id)->sole()->amount_kobo)->toBeNull()
        ->and(settingsOf($this->acme)->pluck('property_id')->unique()->all())->toBe([$this->palm->id])
        ->and(AuditEntry::query()->where('event', 'utility_setting.added')->count())->toBe(3);

    // The database refuses a second row for a unit and utility, whatever the code does.
    expect(fn () => inOrganization($this->acme, fn () => tap(new UnitUtilitySetting)->forceFill([
        'property_id' => $this->palm->id,
        'unit_id' => $this->flat1->id,
        'utility_type_id' => $this->electricity->id,
        'effective_from' => '2027-02-01',
    ])->save()))->toThrow(UniqueConstraintViolationException::class);

    // Units must be the building's; another building's or organization's unit is refused.
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $oakFlat = unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']);
    $foreignUnit = unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court']));
    $security = utilityTypeIn($this->acme, $this->owner);
    expect(utilityErrors(fn () => inOrganization($this->acme, fn () => $settings->add($this->owner, [...$input, 'utilityType' => $security->id, 'units' => [$this->flat1->id, $oakFlat->id]]))))->toBe(['units' => 'Choose units of this building.'])
        ->and(utilityErrors(fn () => inOrganization($this->acme, fn () => $settings->add($this->owner, [...$input, 'utilityType' => $security->id, 'units' => [$foreignUnit->id]]))))->toBe(['units' => 'Choose units of this building.'])
        ->and(utilityErrors(fn () => inOrganization($this->acme, fn () => $settings->add($this->owner, [...$input, 'utilityType' => $security->id, 'units' => []]))))->toHaveKey('units')
        ->and(settingsOf($this->acme))->toHaveCount(3);
});

it('overrides the amount and cycle per unit, falls back to the type\'s defaults, and switches a utility off and on', function () {
    $security = utilityTypeIn($this->acme, $this->owner);
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $security);
    $settings = app(UnitUtilitySettings::class);
    $edit = fn (array $changes) => inOrganization($this->acme, fn () => $settings->update($this->owner, storedSetting($this->acme, $setting), ['active' => true, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => '', ...$changes]));

    expect(storedSetting($this->acme, $setting)->amount()->kobo)->toBe(2_500_000)
        ->and(storedSetting($this->acme, $setting)->billingCycle())->toBe(BillingCycle::Monthly);

    $edit(['amount' => '30,000', 'cycle' => 'quarterly', 'effectiveFrom' => '2027-02-01']);
    $stored = storedSetting($this->acme, $setting);
    expect($stored->amount_kobo?->kobo)->toBe(3_000_000)
        ->and($stored->amount()->kobo)->toBe(3_000_000)
        ->and($stored->billingCycle())->toBe(BillingCycle::Quarterly)
        ->and($stored->effective_from->toDateString())->toBe('2027-02-01')
        ->and(AuditEntry::query()->where('event', 'utility_setting.updated')->sole()->properties['changed'])->toEqualCanonicalizing(['amount_kobo', 'cycle', 'effective_from']);

    // Empty again: the type's defaults, which follow the type.
    $edit(['effectiveFrom' => '2027-02-01']);
    inOrganization($this->acme, fn () => app(UtilityTypes::class)->update($this->owner, storedType($this->acme, $security), ['name' => 'Security levy', 'code' => 'SECURITY', 'chargingMode' => 'flat', 'unitOfMeasure' => '', 'amount' => '27,500', 'cycle' => 'annual']));
    expect(storedSetting($this->acme, $setting)->amount_kobo)->toBeNull()
        ->and(storedSetting($this->acme, $setting)->amount()->kobo)->toBe(2_750_000)
        ->and(storedSetting($this->acme, $setting)->billingCycle())->toBe(BillingCycle::Annual);

    expect(utilityErrors(fn () => $edit(['amount' => 'lots'])))->toHaveKey('amount')
        ->and(utilityErrors(fn () => $edit(['amount' => '0'])))->toHaveKey('amount')
        ->and(utilityErrors(fn () => $edit(['cycle' => 'hourly'])))->toHaveKey('cycle')
        ->and(utilityErrors(fn () => $edit(['effectiveFrom' => '2027-02-30'])))->toHaveKey('effectiveFrom');

    $edit(['active' => false, 'effectiveFrom' => '2027-02-01']);
    expect(storedSetting($this->acme, $setting)->active)->toBeFalse();
    $edit(['active' => true, 'effectiveFrom' => '2027-02-01']);
    expect(storedSetting($this->acme, $setting)->active)->toBeTrue()
        ->and(fn () => inOrganization($this->acme, fn () => storedSetting($this->acme, $setting)->delete()))->toThrow(LogicException::class);
});

it('takes a metered utility\'s starting reading, and keeps it once the meter has readings', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1,200.5']);
    $edit = fn (string $start) => inOrganization($this->acme, fn () => app(UnitUtilitySettings::class)->update($this->owner, storedSetting($this->acme, $setting), ['active' => true, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => $start]));

    expect(storedSetting($this->acme, $setting)->starting_reading?->input())->toBe('1200.5')
        ->and(utilityErrors(fn () => $edit('12.3456')))->toHaveKey('startingReading');

    $edit('1300');
    expect(storedSetting($this->acme, $setting)->startingReading()->input())->toBe('1300');

    readMeter($this->acme, $this->owner, $setting, '2027-01-31', '1400');
    expect(utilityErrors(fn () => $edit('0')))->toBe(['startingReading' => 'This meter has readings already: the starting reading stays as it is. Correct the latest reading instead.'])
        ->and(storedSetting($this->acme, $setting)->startingReading()->input())->toBe('1300');

    // A flat utility has no meter: no starting reading kept.
    expect(utilityOn($this->acme, $this->owner, $this->flat1, utilityTypeIn($this->acme, $this->owner), ['startingReading' => '55'])->starting_reading)->toBeNull();
});

it('acceptance: a reading cannot go backwards: it counts from the last reading, or the starting reading', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['startingReading' => '1000']);

    $january = readMeter($this->acme, $this->owner, $setting, '2027-01-31', '1100.5');
    expect($january->previous_value->input())->toBe('1000')
        ->and($january->consumption->input())->toBe('100.5')
        ->and($january->rate_kobo->kobo)->toBe(25_000)
        ->and($january->meter_replaced)->toBeFalse()
        ->and($january->recorded_by_user_id)->toBe($this->owner->id);

    // Lower than the last reading: refused, nothing stored.
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-28', '1099.999')))
        ->toBe(['value' => 'A meter does not go backwards: the last reading was 1,100.5. If the meter was replaced, tick "Meter replaced".']);

    // The same value is no consumption; then on.
    readMeter($this->acme, $this->owner, $setting, '2027-02-28', '1100.5');
    readMeter($this->acme, $this->owner, $setting, '2027-03-05', '1,250.25');

    expect(meterReadingsOf($this->acme, $setting))->toBe([
        ['2027-01-31', '1000', '1100.5', '100.5'],
        ['2027-02-28', '1100.5', '1100.5', '0'],
        ['2027-03-05', '1100.5', '1250.25', '149.75'],
    ]);

    // Below the starting reading is backwards too.
    $flat2 = utilityOn($this->acme, $this->owner, $this->flat2, $this->electricity, ['startingReading' => '500']);
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $flat2, '2027-01-31', '499')))->toHaveKey('value')
        ->and(meterReadingsOf($this->acme, $flat2))->toBe([]);

    // What a reading must look like.
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-03-08', '1300.1234')))->toHaveKey('value')
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-03-08', 'twelve')))->toHaveKey('value')
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-03-08', '-5')))->toHaveKey('value')
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-03-08', '1300', ['note' => str_repeat('a', 256)])))->toHaveKey('note');
});

it('acceptance: counts from a replaced meter\'s first value when "meter replaced" is ticked, and never below it', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    readMeter($this->acme, $this->owner, $setting, '2027-01-31', '8,750');

    // The new meter shows 12: refused unless marked as replaced.
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-20', '12')))->toHaveKey('value')
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-20', '12', ['meterReplaced' => true, 'newMeterStart' => '20'])))
        ->toBe(['value' => 'The reading cannot be below the new meter\'s first value (20).'])
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-20', '12', ['meterReplaced' => true, 'newMeterStart' => 'zero'])))->toHaveKey('newMeterStart');

    $replaced = readMeter($this->acme, $this->owner, $setting, '2027-02-20', '12', ['meterReplaced' => '1', 'newMeterStart' => '']);
    readMeter($this->acme, $this->owner, $setting, '2027-03-01', '40.5');

    expect($replaced->meter_replaced)->toBeTrue()
        ->and(meterReadingsOf($this->acme, $setting))->toBe([
            ['2027-01-31', '0', '8750', '8750'],
            ['2027-02-20', '0', '12', '12'],
            ['2027-03-01', '12', '40.5', '28.5'],
        ])
        ->and(AuditEntry::query()->where('event', 'utility_reading.recorded')->where('subject_id', $replaced->id)->sole()->properties['meter_replaced'])->toBeTrue();
});

it('acceptance: keeps readings in date order, never two on the same day, none in the future or before the utility applies', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['effectiveFrom' => '2027-01-15']);
    readMeter($this->acme, $this->owner, $setting, '2027-02-01', '100');

    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-01', '120')))
        ->toBe(['readingDate' => 'Readings follow each other by date: the last one was taken on 01/02/2027. Date this one after it.'])
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-01-20', '90')))->toHaveKey('readingDate')
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-03-11', '130')))->toBe(['readingDate' => 'A reading cannot be dated in the future.'])
        ->and(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-31', '130')))->toHaveKey('readingDate');

    $other = utilityOn($this->acme, $this->owner, $this->flat2, $this->electricity, ['effectiveFrom' => '2027-01-15']);
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $other, '2027-01-14', '5')))->toBe(['readingDate' => 'The utility applies to this unit from 15/01/2027: date the reading on or after it.']);

    // Today (Lagos) is fine; the meters of two units do not share dates.
    readMeter($this->acme, $this->owner, $setting, '2027-03-10', '150');
    readMeter($this->acme, $this->owner, $other, '2027-02-01', '7');

    expect(array_column(meterReadingsOf($this->acme, $setting), 0))->toBe(['2027-02-01', '2027-03-10']);

    // The database refuses a second kept reading of a meter on one day, whatever the code does.
    expect(fn () => inOrganization($this->acme, fn () => tap(new UtilityReading)->forceFill([
        'unit_utility_setting_id' => $setting->id,
        'reading_date' => '2027-03-10',
        'previous_value' => MeterValue::zero(),
        'current_value' => MeterValue::parse('151'),
        'consumption' => MeterValue::parse('151'),
        'rate_kobo' => Money::naira(250),
    ])->save()))->toThrow(UniqueConstraintViolationException::class);
});

it('takes readings only for a switched-on metered utility of an active type, on a unit that is not archived', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    $flat = utilityOn($this->acme, $this->owner, $this->flat1, utilityTypeIn($this->acme, $this->owner));

    expect(fn () => readMeter($this->acme, $this->owner, $flat, '2027-02-01', '1'))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => app(UnitUtilitySettings::class)->update($this->owner, storedSetting($this->acme, $setting), ['active' => false, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => '']));
    expect(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-01', '1'))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => app(UnitUtilitySettings::class)->update($this->owner, storedSetting($this->acme, $setting), ['active' => true, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => '']));
    inOrganization($this->acme, fn () => app(UtilityTypes::class)->archive($this->owner, storedType($this->acme, $this->electricity)));
    expect(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-01', '1'))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => app(UtilityTypes::class)->restore($this->owner, storedType($this->acme, $this->electricity)));
    inOrganization($this->acme, fn () => $this->flat1->fresh()?->delete());
    expect(utilityErrors(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-01', '1')))->toBe(['value' => 'This unit is archived: its meter takes no more readings.'])
        ->and(meterReadingsOf($this->acme, $setting))->toBe([]);
});

it('keeps the rate per unit of measure each reading was taken at', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity, ['amount' => '275.50']);
    $january = readMeter($this->acme, $this->owner, $setting, '2027-01-31', '100');

    inOrganization($this->acme, fn () => app(UnitUtilitySettings::class)->update($this->owner, storedSetting($this->acme, $setting), ['active' => true, 'amount' => '', 'cycle' => '', 'effectiveFrom' => '2027-01-01', 'startingReading' => '']));
    $february = readMeter($this->acme, $this->owner, $setting, '2027-02-28', '160');

    expect(storedReading($this->acme, $january)->rate_kobo->kobo)->toBe(27_550)
        ->and(storedReading($this->acme, $february)->rate_kobo->kobo)->toBe(25_000);
});

it('corrects or removes the latest reading before it is billed, audited, and frees the removed reading\'s day', function () {
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    $readings = app(MeterReadings::class);
    $january = readMeter($this->acme, $this->owner, $setting, '2027-01-31', '100');
    $february = readMeter($this->acme, $this->owner, $setting, '2027-02-28', '1600');
    $correct = fn (UtilityReading $reading, array $changes) => inOrganization($this->acme, fn () => $readings->correct($this->owner, storedReading($this->acme, $reading), ['readingDate' => '2027-02-28', 'value' => '160', 'meterReplaced' => false, 'newMeterStart' => '', 'note' => '', ...$changes]));

    // Only the latest reading.
    expect(fn () => $correct($january, ['readingDate' => '2027-01-31', 'value' => '90']))->toThrow(AuthorizationException::class);

    // Under the same rules, against the reading before it.
    expect(utilityErrors(fn () => $correct($february, ['value' => '99'])))->toHaveKey('value')
        ->and(utilityErrors(fn () => $correct($february, ['readingDate' => '2027-01-31'])))->toHaveKey('readingDate');

    $correct($february, ['value' => '160', 'note' => 'Typo: one zero too many']);
    $audit = AuditEntry::query()->where('event', 'utility_reading.corrected')->sole();
    expect(meterReadingsOf($this->acme, $setting))->toBe([['2027-01-31', '0', '100', '100'], ['2027-02-28', '100', '160', '60']])
        ->and(storedReading($this->acme, $february)->note)->toBe('Typo: one zero too many')
        ->and($audit->properties['before'])->toEqual(['reading_date' => '2027-02-28', 'previous_value' => '100.000', 'current_value' => '1600.000', 'consumption' => '1500.000', 'meter_replaced' => false])
        ->and($audit->properties['after'])->toEqual(['reading_date' => '2027-02-28', 'previous_value' => '100.000', 'current_value' => '160.000', 'consumption' => '60.000', 'meter_replaced' => false]);

    // Remove: a reason is required; the reading is kept as removed and its day is free again.
    expect(utilityErrors(fn () => inOrganization($this->acme, fn () => $readings->remove($this->owner, storedReading($this->acme, $february), ' '))))->toHaveKey('removalReason');
    inOrganization($this->acme, fn () => $readings->remove($this->owner, storedReading($this->acme, $february), 'Read the wrong   flat'));

    $removed = storedReading($this->acme, $february);
    expect($removed->trashed())->toBeTrue()
        ->and($removed->live)->toBeNull()
        ->and($removed->removed_by_user_id)->toBe($this->owner->id)
        ->and($removed->removal_reason)->toBe('Read the wrong flat')
        ->and(AuditEntry::query()->where('event', 'utility_reading.removed')->sole()->properties['reason'])->toBe('Read the wrong flat')
        ->and(meterReadingsOf($this->acme, $setting))->toBe([['2027-01-31', '0', '100', '100']]);

    readMeter($this->acme, $this->owner, $setting, '2027-02-28', '170');
    expect(meterReadingsOf($this->acme, $setting))->toBe([['2027-01-31', '0', '100', '100'], ['2027-02-28', '100', '170', '70']])
        ->and(fn () => inOrganization($this->acme, fn () => storedReading($this->acme, $february)->forceDelete()))->toThrow(LogicException::class);
});

it('never changes a billed reading', function () {
    $kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    activeLease($this->acme, $this->owner, $this->flat2, $kemi);
    runBillGeneration();
    $bill = billsOf($this->acme)->first();
    expect($bill)->toBeInstanceOf(Bill::class);

    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    $reading = readMeter($this->acme, $this->owner, $setting, '2027-01-31', '100');
    // P03-06b links a reading to its bill once.
    inOrganization($this->acme, fn () => storedReading($this->acme, $reading)->forceFill(['bill_id' => $bill->id])->save());

    $readings = app(MeterReadings::class);
    expect(fn () => inOrganization($this->acme, fn () => $readings->correct($this->owner, storedReading($this->acme, $reading), ['readingDate' => '2027-01-31', 'value' => '90'])))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $readings->remove($this->owner, storedReading($this->acme, $reading), 'Mistake')))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => storedReading($this->acme, $reading)->forceFill(['note' => 'changed'])->save()))->toThrow(LogicException::class)
        ->and(storedReading($this->acme, $reading)->current_value->input())->toBe('100');
});

it('keeps a photo of the meter as evidence in its own collection, kept when the reading is removed', function () {
    Storage::fake('media');
    $setting = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    $reading = readMeter($this->acme, $this->owner, $setting, '2027-01-31', '100', [], mediaImage('meter.jpg', 80, 60));

    $media = inOrganization($this->acme, fn () => storedReading($this->acme, $reading)->getFirstMedia(UtilityReading::PHOTO_COLLECTION));
    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->organization_id)->toBe($this->acme->id)
        ->and($media->collection_name)->toBe('meter_photos')
        ->and(config('media.collections.meter_photos.kind'))->toBe('evidence')
        ->and(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(EvidenceFileProtected::class);

    inOrganization($this->acme, fn () => app(MeterReadings::class)->remove($this->owner, storedReading($this->acme, $reading), 'Wrong meter'));
    expect(inOrganization($this->acme, fn () => Media::query()->whereKey($media->id)->exists()))->toBeTrue();

    // Only images; a refused file stores nothing.
    expect(fn () => readMeter($this->acme, $this->owner, $setting, '2027-02-01', '120', [], mediaPdf('meter.pdf')))->toThrow(UploadRefused::class)
        ->and(meterReadingsOf($this->acme, $setting))->toBe([]);
});

it('reads meter values exactly, as whole thousandths', function () {
    expect(MeterValue::parse('1,234,567.891')?->thousandths)->toBe(1_234_567_891)
        ->and(MeterValue::parse('0.1')?->toDecimalString())->toBe('0.100')
        ->and(MeterValue::parse(' 12 345 ')?->format())->toBe('12,345')
        ->and(MeterValue::parse('1,23.4'))->toBeNull()
        ->and(MeterValue::parse('1e3'))->toBeNull()
        ->and(MeterValue::parse('1234567890123'))->toBeNull()
        ->and(MeterValue::parse('999999999999.999')?->thousandths)->toBe(MeterValue::MAX_THOUSANDTHS)
        ->and(MeterValue::parse('10.5')?->minus(MeterValue::parse('0.75') ?? MeterValue::zero())->format())->toBe('9.75')
        ->and(fn () => MeterValue::parse('1')?->minus(MeterValue::parse('2') ?? MeterValue::zero()))->toThrow(InvalidArgumentException::class);
});
