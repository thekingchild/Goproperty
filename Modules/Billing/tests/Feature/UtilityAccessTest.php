<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Utilities\MeterReadings;
use Modules\Billing\Utilities\UnitUtilitySettings;
use Modules\Billing\Utilities\UtilityTypes;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/utility-helpers.php';

/*
 * P03-06a: who may see and change utilities (ARCHITECTURE.md § 5
 * "Utilities"): owners and administrators keep utility types and which
 * units pay them; agents see them and read meters; another organization's
 * type, setting or reading answers 404 everywhere; an agent assigned to
 * buildings sees and reads only those buildings' meters (decision 9); a
 * suspended organization is read-only; impersonating staff never archive
 * nor remove, and read-only sessions change nothing.
 */

beforeEach(function () {
    holdInvoicePdfs();
    seedOrganizationRoles();
    Storage::fake('media');
    $this->travelTo('2027-03-10 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner']), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->flat1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->oak1 = unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']);
    $this->electricity = electricityIn($this->acme, $this->owner);
    $this->palmMeter = utilityOn($this->acme, $this->owner, $this->flat1, $this->electricity);
    $this->oakMeter = utilityOn($this->acme, $this->owner, $this->oak1, $this->electricity);
    $this->palmReading = readMeter($this->acme, $this->owner, $this->palmMeter, '2027-01-31', '100', [], mediaImage('palm-meter.jpg', 80, 60));
    $this->oakReading = readMeter($this->acme, $this->owner, $this->oakMeter, '2027-01-31', '50', [], mediaImage('oak-meter.jpg', 80, 60));

    $foreignUnit = unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']), ['unit_label' => 'Foreign 9']);
    $this->foreignType = electricityIn($this->other, $this->otherOwner, ['name' => 'Foreign power', 'code' => 'FPOWER']);
    $this->foreignMeter = utilityOn($this->other, $this->otherOwner, $foreignUnit, $this->foreignType);
    $this->foreignReading = readMeter($this->other, $this->otherOwner, $this->foreignMeter, '2027-01-31', '999', [], mediaImage('foreign.jpg', 80, 60));
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, bool> ability => allowed, as the utility policies answer inside the organization */
function utilityAbilitiesOf(User $user, Organization $organization, UtilityType $type, UnitUtilitySetting $setting, UtilityReading $reading): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', UtilityType::class),
        'export' => Gate::forUser($user)->allows('export', UtilityType::class) && Gate::forUser($user)->allows('export', UnitUtilitySetting::class) && Gate::forUser($user)->allows('export', UtilityReading::class),
        'createType' => Gate::forUser($user)->allows('create', UtilityType::class),
        'updateType' => Gate::forUser($user)->allows('update', $type),
        'archiveType' => Gate::forUser($user)->allows('delete', $type),
        'viewSetting' => Gate::forUser($user)->allows('view', $setting),
        'addSetting' => Gate::forUser($user)->allows('create', UnitUtilitySetting::class),
        'updateSetting' => Gate::forUser($user)->allows('update', $setting),
        'recordReading' => Gate::forUser($user)->allows('recordReading', $setting) && Gate::forUser($user)->allows('create', UtilityReading::class),
        'correctReading' => Gate::forUser($user)->allows('update', $reading),
        'removeReading' => Gate::forUser($user)->allows('delete', $reading),
        'viewPhoto' => Gate::forUser($user)->allows('viewPhoto', $reading),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in the utility policies', function (OrganizationRole $role, array $expected) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(utilityAbilitiesOf($user, $this->acme, storedType($this->acme, $this->electricity), storedSetting($this->acme, $this->palmMeter), storedReading($this->acme, $this->palmReading)))->toBe($expected);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'export' => true, 'createType' => true, 'updateType' => true, 'archiveType' => true, 'viewSetting' => true, 'addSetting' => true, 'updateSetting' => true, 'recordReading' => true, 'correctReading' => true, 'removeReading' => true, 'viewPhoto' => true]],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'export' => true, 'createType' => true, 'updateType' => true, 'archiveType' => true, 'viewSetting' => true, 'addSetting' => true, 'updateSetting' => true, 'recordReading' => true, 'correctReading' => true, 'removeReading' => true, 'viewPhoto' => true]],
    'agent' => [OrganizationRole::Agent, ['viewAny' => true, 'export' => true, 'createType' => false, 'updateType' => false, 'archiveType' => false, 'viewSetting' => true, 'addSetting' => false, 'updateSetting' => false, 'recordReading' => true, 'correctReading' => true, 'removeReading' => true, 'viewPhoto' => true]],
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'export' => false, 'createType' => false, 'updateType' => false, 'archiveType' => false, 'viewSetting' => false, 'addSetting' => false, 'updateSetting' => false, 'recordReading' => false, 'correctReading' => false, 'removeReading' => false, 'viewPhoto' => false]],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'export' => false, 'createType' => false, 'updateType' => false, 'archiveType' => false, 'viewSetting' => false, 'addSetting' => false, 'updateSetting' => false, 'recordReading' => false, 'correctReading' => false, 'removeReading' => false, 'viewPhoto' => false]],
]);

it('opens the utility pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    foreach ([$this->owner, $admin] as $user) {
        utilitiesPage($user)->assertOk()->assertSee('Electricity')->assertSee('data-nav="utilities"', false)->assertSee('data-test="add-utility-type"', false)->assertSee('data-test="export-utility_types-csv"', false);
        utilitiesPage($user, '/units')->assertOk()->assertSee('Flat 1')->assertSee('data-test="add-utility-setting"', false)->assertSee('data-test="edit-utility-setting-'.$this->palmMeter->id.'"', false);
        utilitiesPage($user, '/readings')->assertOk()->assertSee('data-test="correct-reading-'.$this->palmReading->id.'"', false);
        utilitiesPage($user, "/types/{$this->electricity->id}/edit")->assertOk();
        utilitiesPage($user, '/units/add')->assertOk();
        utilitiesPage($user, "/units/{$this->palmMeter->id}/edit")->assertOk();
        utilitiesPage($user, "/readings/record?meter={$this->palmMeter->id}")->assertOk();
    }

    // Agents see everything and read meters, but keep neither types nor units' settings.
    utilitiesPage($agent)->assertOk()->assertSee('Electricity')->assertSee('data-nav="utilities"', false)->assertDontSee('data-test="add-utility-type"', false)->assertDontSee('data-test="archive-utility-type-', false);
    utilitiesPage($agent, '/units')->assertOk()->assertDontSee('data-test="add-utility-setting"', false)->assertDontSee('data-test="edit-utility-setting-', false)->assertSee('data-test="record-reading-'.$this->palmMeter->id.'"', false);
    utilitiesPage($agent, '/readings')->assertOk()->assertSee('data-test="record-reading"', false);
    utilitiesPage($agent, '/types/create')->assertForbidden();
    utilitiesPage($agent, "/types/{$this->electricity->id}/edit")->assertForbidden();
    utilitiesPage($agent, '/units/add')->assertForbidden();
    utilitiesPage($agent, "/units/{$this->palmMeter->id}/edit")->assertForbidden();
    utilitiesPage($agent, "/readings/{$this->palmReading->id}/edit")->assertOk();
    expect(fn () => utilityTypeIn($this->acme, $agent, ['name' => 'Water', 'code' => 'WATER']))->toThrow(AuthorizationException::class)
        ->and(fn () => utilityOn($this->acme, $agent, $this->flat1, utilityTypeIn($this->acme, $this->owner)))->toThrow(AuthorizationException::class);
    readMeter($this->acme, $agent, $this->palmMeter, '2027-02-28', '140');

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        foreach (['', '/units', '/readings', '/types/create', "/units/{$this->palmMeter->id}/edit", "/readings/{$this->palmReading->id}/edit", "/readings/{$this->palmReading->id}/photo"] as $path) {
            utilitiesPage($user, $path)->assertNotFound();
        }
    }
});

it('answers 404 for another organization\'s utility, setting or reading guessed by ID, on pages, lists and actions', function () {
    foreach (["/types/{$this->foreignType->id}/edit", "/units/{$this->foreignMeter->id}/edit", "/readings/{$this->foreignReading->id}/edit", "/readings/{$this->foreignReading->id}/photo", '/types/999999/edit', '/units/abc/edit'] as $path) {
        utilitiesPage($this->owner, $path)->assertNotFound();
    }

    foreach (['', '?q=Foreign', '?q=FPOWER'] as $query) {
        utilitiesPage($this->owner, $query)->assertOk()->assertDontSee('Foreign power');
    }

    foreach (['/units', '/units?utility='.$this->foreignType->id, '/units?unit='.$this->foreignMeter->unit_id, '/readings', '/readings?meter='.$this->foreignMeter->id, '/readings?utility='.$this->foreignType->id] as $path) {
        $page = utilitiesPage($this->owner, $path)->assertOk()->assertDontSee('Foreign 9');
        expect(listedUtilityRows($page, 'utility-setting'))->not->toContain($this->foreignMeter->id)
            ->and(listedUtilityRows($page, 'utility-reading'))->not->toContain($this->foreignReading->id);
    }

    // The record page never offers or takes another organization's meter.
    utilitiesPage($this->owner, "/readings/record?meter={$this->foreignMeter->id}")->assertOk()->assertDontSee('Foreign 9');
    $record = utilitiesSnapshot($this->owner, '/readings/record');
    utilitiesUpdate($this->owner, $record, ['meter' => (string) $this->foreignMeter->id, 'readingDate' => '2027-03-01', 'value' => '1500'], [['save']])->assertNotFound();

    // Actions with another organization's ids.
    utilitiesUpdate($this->owner, utilitiesSnapshot($this->owner), [], [['archive', [$this->foreignType->id]]])->assertNotFound();

    // A tampered snapshot (production: debug off answers 419).
    config(['app.debug' => false]);
    $data = json_decode(utilitiesSnapshot($this->owner, "/readings/{$this->palmReading->id}/edit"), true);
    $data['data']['readingId'] = $this->foreignReading->id;
    utilitiesUpdate($this->owner, (string) json_encode($data), ['removalReason' => 'Hijack'], [['remove']])->assertStatus(419);

    // The writers refuse another organization's records handed to them.
    $foreignType = storedType($this->other, $this->foreignType);
    $foreignSetting = storedSetting($this->other, $this->foreignMeter);
    $foreignReading = storedReading($this->other, $this->foreignReading);
    expect(fn () => inOrganization($this->acme, fn () => app(UtilityTypes::class)->archive($this->owner, $foreignType)))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(UnitUtilitySettings::class)->update($this->owner, $foreignSetting, ['active' => false, 'effectiveFrom' => '2027-01-01'])))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(MeterReadings::class)->record($this->owner, $foreignSetting, ['readingDate' => '2027-03-01', 'value' => '1500'])))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(MeterReadings::class)->remove($this->owner, $foreignReading, 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(fn () => utilityOn($this->acme, $this->owner, $this->flat1, $foreignType))->toThrow(ValidationException::class)
        ->and(storedType($this->other, $this->foreignType)->active)->toBeTrue()
        ->and(meterReadingsOf($this->other, $this->foreignMeter))->toBe([['2027-01-31', '0', '999', '999']]);

    // A member of both organizations sees each one's own.
    joinAs($this->owner, $this->other);
    expect(listedUtilityRows(utilitiesPage($this->owner, '/units'), 'utility-setting'))->toEqualCanonicalizing([$this->palmMeter->id, $this->oakMeter->id])
        ->and(listedUtilityRows(utilitiesPage($this->owner, '/units', 'other-realty'), 'utility-setting'))->toBe([$this->foreignMeter->id]);
});

it('shows an agent assigned to buildings only those buildings\' utilities and meters, and 404 for the others', function () {
    $agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $agent, $this->palm);

    expect(listedUtilityRows(utilitiesPage($agent, '/units'), 'utility-setting'))->toBe([$this->palmMeter->id])
        ->and(listedUtilityRows(utilitiesPage($agent, '/units?building='.$this->oak->id), 'utility-setting'))->toBe([])
        ->and(listedUtilityRows(utilitiesPage($agent, '/readings'), 'utility-reading'))->toBe([$this->palmReading->id])
        ->and(listedUtilityRows(utilitiesPage($agent, '/readings?meter='.$this->oakMeter->id), 'utility-reading'))->toBe([]);
    utilitiesPage($agent, '/units')->assertDontSee('Oak Lodge')->assertDontSee('Oak 1');
    utilitiesPage($agent, "/readings/{$this->oakReading->id}/edit")->assertNotFound();
    utilitiesPage($agent, "/readings/{$this->oakReading->id}/photo")->assertNotFound();
    utilitiesPage($agent, "/readings/{$this->palmReading->id}/photo")->assertRedirect();

    // The record page offers their buildings' meters only, and refuses another one.
    utilitiesPage($agent, "/readings/record?meter={$this->oakMeter->id}")->assertOk()->assertDontSee('Oak 1');
    utilitiesPage($agent, "/readings/record?building={$this->palm->id}")->assertOk()->assertSee('Flat 1 · Electricity');
    $record = utilitiesSnapshot($agent, '/readings/record');
    utilitiesUpdate($agent, $record, ['meter' => (string) $this->oakMeter->id, 'readingDate' => '2027-03-01', 'value' => '75'], [['save']])->assertNotFound();
    expect(fn () => readMeter($this->acme, $agent, $this->oakMeter, '2027-03-01', '75'))->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404))
        ->and(fn () => inOrganization($this->acme, fn () => app(MeterReadings::class)->remove($agent, storedReading($this->acme, $this->oakReading), 'Not mine')))->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));

    // The exports hold the same rows as the pages.
    expect(exportColumn(exportRows($agent, '/utilities/units', 'utility_settings'), 'Unit'))->toBe(['Flat 1'])
        ->and(exportColumn(exportRows($agent, '/utilities/readings', 'utility_readings'), 'Unit'))->toBe(['Flat 1']);

    // Their own buildings' meters they read through the page.
    $palm = utilitiesSnapshot($agent, "/readings/record?meter={$this->palmMeter->id}");
    $saved = utilitiesUpdate($agent, $palm, ['readingDate' => '2027-03-01', 'value' => '130'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($saved))->toContain('/utilities/readings?meter='.$this->palmMeter->id)
        ->and(meterReadingsOf($this->acme, $this->palmMeter))->toBe([['2027-01-31', '0', '100', '100'], ['2027-03-01', '100', '130', '30']])
        ->and(meterReadingsOf($this->acme, $this->oakMeter))->toBe([['2027-01-31', '0', '50', '50']]);

    // An agent with no assignment sees every building's.
    expect(listedUtilityRows(utilitiesPage(joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent), '/units'), 'utility-setting'))->toHaveCount(2);
});

it('shows utilities read-only while the organization is suspended, and still exports them', function () {
    $types = utilitiesSnapshot($this->owner);
    $record = utilitiesSnapshot($this->owner, "/readings/record?meter={$this->palmMeter->id}");
    $edit = utilitiesSnapshot($this->owner, "/units/{$this->palmMeter->id}/edit");
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    utilitiesPage($this->owner)->assertOk()->assertSee('Electricity')->assertDontSee('data-test="add-utility-type"', false)->assertDontSee('data-test="archive-utility-type-', false);
    utilitiesPage($this->owner, '/units')->assertOk()->assertDontSee('data-test="add-utility-setting"', false)->assertDontSee('data-test="record-reading-', false);
    utilitiesPage($this->owner, '/readings')->assertOk()->assertDontSee('data-test="record-reading"', false);
    utilitiesPage($this->owner, "/readings/{$this->palmReading->id}/photo")->assertRedirect();
    utilitiesPage($this->owner, '/types/create')->assertForbidden();

    utilitiesUpdate($this->owner, $types, [], [['archive', [$this->electricity->id]]])->assertForbidden();
    utilitiesUpdate($this->owner, $record, ['readingDate' => '2027-03-01', 'value' => '130'], [['save']])->assertForbidden();
    utilitiesUpdate($this->owner, $edit, ['active' => false], [['save']])->assertForbidden();

    expect(storedType($this->acme, $this->electricity)->active)->toBeTrue()
        ->and(storedSetting($this->acme, $this->palmMeter)->active)->toBeTrue()
        ->and(meterReadingsOf($this->acme, $this->palmMeter))->toHaveCount(1);

    foreach (['' => 'utility_types', '/units' => 'utility_settings', '/readings' => 'utility_readings'] as $path => $list) {
        $pressed = utilitiesUpdate($this->owner, utilitiesSnapshot($this->owner, $path), [], [['export', [$list, 'csv']]])->assertOk();
        expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
    }
});

it('lets impersonating staff look at and export utilities in a read-only session, and change nothing', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $types = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/utilities')->assertOk()->assertSee('Electricity')->assertSee('data-test="export-utility_types-csv"', false)->assertDontSee('data-test="add-utility-type"', false));
    // A read-only session records nothing: the record page itself is refused.
    asSupport()->get("/o/acme-homes/utilities/readings/record?meter={$this->palmMeter->id}")->assertForbidden();
    $readings = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/utilities/readings')->assertOk()->assertDontSee('data-test="record-reading"', false));

    freshRequest();
    LivewireHttp::update(test(), $types, [], [['archive', [$this->electricity->id]]])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), $readings, [], [['export', ['utility_readings', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedType($this->acme, $this->electricity)->active)->toBeTrue()
        ->and(meterReadingsOf($this->acme, $this->palmMeter))->toHaveCount(1);
});

it('lets impersonating staff in write mode record and correct readings, audited with both identities, but never archive a utility or remove a reading', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $record = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/utilities/readings/record?meter={$this->palmMeter->id}")->assertOk());
    freshRequest();
    LivewireHttp::update(test(), $record, ['readingDate' => '2027-03-01', 'value' => '130'], [['save']])->assertOk();

    $types = LivewireHttp::snapshot(asSupport()->get('/o/acme-homes/utilities')->assertOk()->assertDontSee('data-test="archive-utility-type-', false));
    freshRequest();
    LivewireHttp::update(test(), $types, [], [['archive', [$this->electricity->id]]])->assertForbidden();

    $latest = inOrganization($this->acme, fn () => UtilityReading::query()->where('unit_utility_setting_id', $this->palmMeter->id)->orderByDesc('reading_date')->firstOrFail());
    $edit = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/utilities/readings/{$latest->id}/edit")->assertOk()->assertDontSee('data-test="remove-reading"', false));
    freshRequest();
    LivewireHttp::update(test(), $edit, ['removalReason' => 'Mistake'], [['remove']])->assertForbidden();

    $recorded = AuditEntry::query()->where('event', 'utility_reading.recorded')->where('subject_id', $latest->id)->sole();
    expect(meterReadingsOf($this->acme, $this->palmMeter))->toBe([['2027-01-31', '0', '100', '100'], ['2027-03-01', '100', '130', '30']])
        ->and($recorded->causer_id)->toBe($this->owner->id)
        ->and($recorded->impersonator_staff_id)->toBe($staff->id)
        ->and(storedType($this->acme, $this->electricity)->active)->toBeTrue()
        ->and(storedReading($this->acme, $latest)->trashed())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
});
