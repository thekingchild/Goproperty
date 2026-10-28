<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Billing\Livewire\UtilityReadingForm;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\UtilityTypes;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/utility-helpers.php';

/*
 * P03-06a: the utilities pages (/o/{slug}/utilities, /utilities/units,
 * /utilities/readings and their forms): lists with filters in the URL and
 * their exports (P02-07: same rows as the page, Excel and CSV, audited),
 * the type, setting and reading forms through Livewire, and the meter
 * photo link.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2027-03-10 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->flat1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->flat2 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
    $this->oak1 = unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']);
});

afterEach(fn () => billsFreshRequest());

it('lists utility types with a search and filters in the URL, and exports them', function () {
    $security = utilityTypeIn($this->acme, $this->owner);
    $electricity = electricityIn($this->acme, $this->owner);
    $water = utilityTypeIn($this->acme, $this->owner, ['name' => 'Water', 'code' => 'WTR-1', 'chargingMode' => 'metered', 'unitOfMeasure' => 'cubic_metre', 'amount' => '1,200', 'cycle' => 'quarterly']);
    utilityOn($this->acme, $this->owner, $this->flat1, $security);
    utilityOn($this->acme, $this->owner, $this->flat2, $security);
    inOrganization($this->acme, fn () => app(UtilityTypes::class)->archive($this->owner, storedType($this->acme, $water)));

    // Active first, then by name.
    expect(listedUtilityRows(utilitiesPage($this->owner), 'utility-type'))->toBe([$electricity->id, $security->id, $water->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '?q=secur'), 'utility-type'))->toBe([$security->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '?q=wtr'), 'utility-type'))->toBe([$water->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '?status=archived'), 'utility-type'))->toBe([$water->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '?status=active&charging=metered'), 'utility-type'))->toBe([$electricity->id]);
    utilitiesPage($this->owner)->assertSee('2 units charged')->assertSee('₦250 per kWh')->assertSee('data-test="restore-utility-type-'.$water->id.'"', false);
    utilitiesPage($this->owner, '?q=nothing-like-this')->assertSee('No utility matches')->assertSee('data-test="clear-filters"', false);

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/utilities', 'utility_types', $format);

        expect($rows)->toBe([
            ['Name', 'Code', 'Charging', 'Unit of measure', 'Default amount', 'Default cycle', 'Units charged', 'Status'],
            ['Electricity', 'ELEC', 'Metered', 'kWh', '250.00', 'Monthly', '0', 'Active'],
            ['Security levy', 'SECURITY', 'Flat', '', '25000.00', 'Monthly', '2', 'Active'],
            ['Water', 'WTR-1', 'Metered', 'm³', '1200.00', 'Quarterly', '0', 'Archived'],
        ]);
    }

    expect(exportColumn(exportRows($this->owner, '/utilities?status=archived', 'utility_types'), 'Name'))->toBe(['Water'])
        ->and(AuditEntry::query()->where('log_name', 'exports')->latest('id')->first()?->properties['list'])->toBe('utility_types');
});

it('adds, edits, archives and restores a utility type through the pages', function () {
    $form = utilitiesSnapshot($this->owner, '/types/create');

    // Errors stay on the form.
    $refused = utilitiesUpdate($this->owner, $form, ['name' => 'Electricity', 'code' => '', 'chargingMode' => 'metered', 'unitOfMeasure' => '', 'amount' => 'cheap'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKeys(['code', 'unitOfMeasure', 'amount']);

    $saved = utilitiesUpdate($this->owner, $form, ['name' => 'Electricity', 'code' => 'elec', 'chargingMode' => 'metered', 'unitOfMeasure' => 'kwh', 'amount' => '250', 'cycle' => 'monthly'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($saved))->toEndWith('/o/acme-homes/utilities');
    $type = inOrganization($this->acme, fn () => UtilityType::query()->where('code', 'ELEC')->sole());

    $edit = utilitiesSnapshot($this->owner, "/types/{$type->id}/edit");
    utilitiesUpdate($this->owner, $edit, ['amount' => '275.50'], [['save']])->assertOk();
    expect(storedType($this->acme, $type)->default_amount_kobo->kobo)->toBe(27_550);

    // Once a unit pays it, the page shows how it charges as fixed.
    utilityOn($this->acme, $this->owner, $this->flat1, $type);
    utilitiesPage($this->owner, "/types/{$type->id}/edit")->assertOk()->assertSee('data-test="charging-locked"', false);

    $list = utilitiesSnapshot($this->owner);
    utilitiesUpdate($this->owner, $list, [], [['archive', [$type->id]]])->assertOk()->assertSee('Electricity is archived.');
    expect(storedType($this->acme, $type)->active)->toBeFalse();
    utilitiesPage($this->owner, "/types/{$type->id}/edit")->assertForbidden();
    utilitiesUpdate($this->owner, utilitiesSnapshot($this->owner), [], [['restore', [$type->id]]])->assertOk()->assertSee('Electricity is active again.');
    expect(storedType($this->acme, $type)->active)->toBeTrue();
});

it('adds a utility to every unit of a building through the page, skipping units that have it, and edits one unit\'s', function () {
    $security = utilityTypeIn($this->acme, $this->owner);
    utilityOn($this->acme, $this->owner, $this->flat2, $security);

    $form = utilitiesSnapshot($this->owner, '/units/add');
    $chosen = utilitiesUpdate($this->owner, $form, ['building' => (string) $this->palm->id, 'utility' => (string) $security->id])->assertOk();
    // Every unit of the building is chosen at first.
    expect(LivewireHttp::html($chosen))->toContain('Flat 1')->toContain('Flat 2')->not->toContain('Oak 1');

    $saved = utilitiesUpdate($this->owner, LivewireHttp::next($chosen), ['amount' => '30,000', 'effectiveFrom' => '2027-02-01'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($saved))->toContain('/o/acme-homes/utilities/units?building='.$this->palm->id);

    $page = utilitiesPage($this->owner, '/units?building='.$this->palm->id)->assertOk()
        ->assertSee('The utility is added to 1 unit. Already paying it, left as they were: Flat 2.');
    $flat1 = settingsOf($this->acme)->firstWhere('unit_id', $this->flat1->id);
    expect($flat1->amount_kobo?->kobo)->toBe(3_000_000)
        ->and($flat1->effective_from->toDateString())->toBe('2027-02-01')
        ->and(listedUtilityRows($page, 'utility-setting'))->toBe(settingsOf($this->acme)->sortBy('unit_id')->pluck('id')->values()->all());

    // No unit chosen: refused.
    $none = utilitiesUpdate($this->owner, utilitiesSnapshot($this->owner, '/units/add?building='.$this->oak->id.'&utility='.$security->id), ['units' => []], [['save']])->assertOk();
    expect(LivewireHttp::errors($none))->toHaveKey('units');

    // One unit's: switched off, back to the default amount.
    $edit = utilitiesSnapshot($this->owner, "/units/{$flat1->id}/edit");
    utilitiesUpdate($this->owner, $edit, ['amount' => '', 'active' => false], [['save']])->assertOk();
    expect(storedSetting($this->acme, $flat1)->active)->toBeFalse()
        ->and(storedSetting($this->acme, $flat1)->amount_kobo)->toBeNull();
    utilitiesPage($this->owner, '/units?status=off')->assertSee('Flat 1')->assertDontSee('Flat 2');
});

it('lists the units\' utilities with filters and the latest reading, and exports them', function () {
    $security = utilityTypeIn($this->acme, $this->owner);
    $electricity = electricityIn($this->acme, $this->owner);
    $palmSecurity = utilityOn($this->acme, $this->owner, $this->flat1, $security, ['amount' => '30,000', 'cycle' => 'quarterly']);
    $palmPower = utilityOn($this->acme, $this->owner, $this->flat1, $electricity, ['startingReading' => '900']);
    $oakPower = utilityOn($this->acme, $this->owner, $this->oak1, $electricity, ['effectiveFrom' => '2027-02-01']);
    readMeter($this->acme, $this->owner, $palmPower, '2027-01-31', '1,000.5');
    readMeter($this->acme, $this->owner, $palmPower, '2027-02-28', '1100');

    expect(listedUtilityRows(utilitiesPage($this->owner, '/units'), 'utility-setting'))->toBe([$palmSecurity->id, $palmPower->id, $oakPower->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/units?building='.$this->oak->id), 'utility-setting'))->toBe([$oakPower->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/units?utility='.$security->id), 'utility-setting'))->toBe([$palmSecurity->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/units?unit='.$this->flat1->id), 'utility-setting'))->toBe([$palmSecurity->id, $palmPower->id]);
    utilitiesPage($this->owner, '/units')->assertSee('last reading 1,100 on 28/02/2027')->assertSee('starts at 0, no reading yet')->assertSee('data-test="default-amount-'.$oakPower->id.'"', false);

    foreach (['csv', 'xlsx'] as $format) {
        expect(exportRows($this->owner, '/utilities/units', 'utility_settings', $format))->toBe([
            ['Building', 'Unit', 'Utility', 'Charging', 'Amount', 'Unit of measure', 'Amount set for this unit', 'Cycle', 'Status', 'Applies from', 'Starting reading', 'Last reading', 'Last reading date'],
            ['Palm Court', 'Flat 1', 'Security levy', 'Flat', '30000.00', '', 'Yes', 'Quarterly', 'On', '2027-01-01', '', '', ''],
            ['Palm Court', 'Flat 1', 'Electricity', 'Metered', '250.00', 'kWh', 'No', 'Monthly', 'On', '2027-01-01', '900', '1100', '2027-02-28'],
            ['Oak Lodge', 'Oak 1', 'Electricity', 'Metered', '250.00', 'kWh', 'No', 'Monthly', 'On', '2027-02-01', '', '', ''],
        ]);
    }

    expect(exportColumn(exportRows($this->owner, '/utilities/units?building='.$this->oak->id, 'utility_settings'), 'Unit'))->toBe(['Oak 1']);
});

it('records a meter reading through the page, showing the last one, and refuses one that goes backwards', function () {
    $electricity = electricityIn($this->acme, $this->owner);
    $meter = utilityOn($this->acme, $this->owner, $this->flat1, $electricity, ['startingReading' => '500']);

    utilitiesPage($this->owner, '/readings/record?meter='.$meter->id)->assertOk()->assertSee('No reading yet: the meter started at 500 on 01/01/2027.')->assertSee('Flat 1 · Electricity');
    $form = utilitiesSnapshot($this->owner, '/readings/record?meter='.$meter->id);

    $refused = utilitiesUpdate($this->owner, $form, ['readingDate' => '2027-03-01', 'value' => '499'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('value')
        ->and(meterReadingsOf($this->acme, $meter))->toBe([]);

    $saved = utilitiesUpdate($this->owner, $form, ['readingDate' => '2027-03-01', 'value' => '612.25', 'note' => 'Read by the caretaker'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($saved))->toContain('/utilities/readings?meter='.$meter->id);
    utilitiesPage($this->owner, '/readings?meter='.$meter->id)->assertSee('The reading is recorded: 612.25, 112.25 used since the last one.');

    utilitiesPage($this->owner, '/readings/record?meter='.$meter->id)->assertSee('Last reading: 612.25 on 01/03/2027.');

    // Meter replaced through the page.
    $replaced = utilitiesUpdate($this->owner, utilitiesSnapshot($this->owner, '/readings/record?meter='.$meter->id), ['readingDate' => '2027-03-09', 'value' => '3', 'meterReplaced' => true, 'newMeterStart' => '0'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($replaced))->toBeString()
        ->and(meterReadingsOf($this->acme, $meter))->toBe([['2027-03-01', '500', '612.25', '112.25'], ['2027-03-09', '0', '3', '3']]);
    utilitiesPage($this->owner, '/readings')->assertSee('data-test="meter-replaced-', false);
});

it('uploads a photo of the meter with the reading, and links it with a short-lived signed link', function () {
    Storage::fake('media');
    $meter = utilityOn($this->acme, $this->owner, $this->flat1, electricityIn($this->acme, $this->owner));

    test()->actingAs($this->owner);
    app(OrganizationContext::class)->set($this->acme);
    $page = Livewire::withQueryParams(['meter' => (string) $meter->id])->test(UtilityReadingForm::class);
    $now = now();
    test()->travelBack();
    $page->set('photo', UploadedFile::fake()->createWithContent('meter.jpg', (string) file_get_contents(mediaImage('meter.jpg', 80, 60)->getPathname())));
    test()->travelTo($now);
    $page->set('readingDate', '2027-03-01')->set('value', '40')->call('save')->assertHasNoErrors();

    $reading = inOrganization($this->acme, fn () => UtilityReading::query()->sole());
    expect(inOrganization($this->acme, fn () => $reading->getFirstMedia(UtilityReading::PHOTO_COLLECTION)?->name))->toBe('meter');
    app(OrganizationContext::class)->clear();

    utilitiesPage($this->owner, '/readings')->assertSee('data-test="reading-photo-'.$reading->id.'"', false);
    $link = utilitiesPage($this->owner, "/readings/{$reading->id}/photo")->assertRedirect()->headers->get('Location');
    expect($link)->toContain('/media/'.$this->acme->id.'/')->toContain('signature=')->toContain('expires=');

    // A PDF is not a meter photo.
    test()->actingAs($this->owner);
    app(OrganizationContext::class)->set($this->acme);
    $pdf = Livewire::withQueryParams(['meter' => (string) $meter->id])->test(UtilityReadingForm::class);
    test()->travelBack();
    $pdf->set('photo', mediaPdf('meter.pdf'));
    test()->travelTo($now);
    $pdf->set('readingDate', '2027-03-05')->set('value', '45')->call('save')->assertHasErrors('photo');
    expect(inOrganization($this->acme, fn () => UtilityReading::query()->count()))->toBe(1);
});

it('lists meter readings newest first with filters, corrects and removes the latest through the page, and exports them', function () {
    $electricity = electricityIn($this->acme, $this->owner);
    $palm = utilityOn($this->acme, $this->owner, $this->flat1, $electricity);
    $oak = utilityOn($this->acme, $this->owner, $this->oak1, $electricity);
    $agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $january = readMeter($this->acme, $this->owner, $palm, '2027-01-31', '100');
    $oakJanuary = readMeter($this->acme, $agent, $oak, '2027-01-31', '40', ['note' => '=HYPERLINK("x")']);
    $february = readMeter($this->acme, $agent, $palm, '2027-02-28', '1600');

    expect(listedUtilityRows(utilitiesPage($this->owner, '/readings'), 'utility-reading'))->toBe([$february->id, $oakJanuary->id, $january->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/readings?meter='.$palm->id), 'utility-reading'))->toBe([$february->id, $january->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/readings?building='.$this->oak->id), 'utility-reading'))->toBe([$oakJanuary->id]);
    // Only the latest reading of each meter is offered for correction.
    utilitiesPage($this->owner, '/readings')->assertSee('data-test="correct-reading-'.$february->id.'"', false)->assertSee('data-test="correct-reading-'.$oakJanuary->id.'"', false)->assertDontSee('data-test="correct-reading-'.$january->id.'"', false);
    utilitiesPage($this->owner, "/readings/{$january->id}/edit")->assertForbidden();

    foreach (['csv', 'xlsx'] as $format) {
        expect(exportRows($this->owner, '/utilities/readings', 'utility_readings', $format))->toBe([
            ['Date', 'Building', 'Unit', 'Utility', 'Previous reading', 'Reading', 'Consumption', 'Unit of measure', 'Meter replaced', 'Rate', 'Billed', 'Recorded by', 'Recorded on', 'Note'],
            ['2027-02-28', 'Palm Court', 'Flat 1', 'Electricity', '100', '1600', '1500', 'kWh', 'No', '250.00', 'No', 'Bola Agent', '2027-03-10 10:00', ''],
            ['2027-01-31', 'Oak Lodge', 'Oak 1', 'Electricity', '0', '40', '40', 'kWh', 'No', '250.00', 'No', 'Bola Agent', '2027-03-10 10:00', "'=HYPERLINK(\"x\")"],
            ['2027-01-31', 'Palm Court', 'Flat 1', 'Electricity', '0', '100', '100', 'kWh', 'No', '250.00', 'No', 'Ada Owner', '2027-03-10 10:00', ''],
        ]);
    }

    // Correct, then remove, through the page.
    $edit = utilitiesSnapshot($agent, "/readings/{$february->id}/edit");
    utilitiesUpdate($agent, $edit, ['value' => '160'], [['save']])->assertOk();
    expect(meterReadingsOf($this->acme, $palm))->toBe([['2027-01-31', '0', '100', '100'], ['2027-02-28', '100', '160', '60']]);

    $refused = utilitiesUpdate($agent, utilitiesSnapshot($agent, "/readings/{$february->id}/edit"), ['removalReason' => ''], [['remove']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('removalReason');
    $removed = utilitiesUpdate($agent, utilitiesSnapshot($agent, "/readings/{$february->id}/edit"), ['removalReason' => 'Wrong flat'], [['remove']])->assertOk();
    expect(LivewireHttp::redirect($removed))->toContain('/utilities/readings?meter='.$palm->id)
        ->and(storedReading($this->acme, $february)->trashed())->toBeTrue()
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/readings'), 'utility-reading'))->toBe([$oakJanuary->id, $january->id]);
    utilitiesPage($this->owner, "/readings/{$february->id}/edit")->assertNotFound();
});

it('reads the names on a page of readings and settings in a fixed number of queries', function () {
    $electricity = electricityIn($this->acme, $this->owner);
    $count = function (string $path): int {
        billsFreshRequest();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($this->owner)->get("/o/acme-homes/utilities{$path}")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $meter = utilityOn($this->acme, $this->owner, $this->flat1, $electricity);
    readMeter($this->acme, $this->owner, $meter, '2027-01-01', '1');
    // The first request warms what every request shares (settings, permissions).
    $count('/units');
    $few = [$count('/units'), $count('/readings')];

    for ($day = 2; $day <= 21; $day++) {
        $unit = unitIn($this->acme, $this->palm, ['unit_label' => "Flat {$day}0"]);
        readMeter($this->acme, $this->owner, utilityOn($this->acme, $this->owner, $unit, $electricity), sprintf('2027-01-%02d', $day), (string) $day);
    }

    // 20 more rows must not mean more queries (an N+1 would add about 20).
    // A margin of 2 absorbs the odd once-a-while query a request makes (a
    // session or cache write), which made an exact count flaky on CI.
    [$units, $readings] = [$count('/units'), $count('/readings')];
    expect($units)->toBeLessThanOrEqual($few[0] + 2)
        ->and($readings)->toBeLessThanOrEqual($few[1] + 2);
});
