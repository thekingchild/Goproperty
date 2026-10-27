<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Casts\MoneyCast;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Events\UnitDeleted;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\CreateUnitsInBulk;
use Modules\Properties\Units\Units;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P02-02: one unit at a time (add, edit, archive, restore), the status
 * board and occupancy counts on the building page (AC: "unit statuses
 * drive occupancy counts on the building page"), rent kept as kobo through
 * MoneyCast, an archived building refusing new units, and another
 * organization's units unreachable by ID.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('adds a unit from the page, audited, and sends UnitCreated', function () {
    Event::fake([UnitCreated::class]);
    $snapshot = unitsSnapshot($this->owner, 'palm-court', '/create');

    $response = LivewireHttp::update($this, $snapshot, unitForm(['unitLabel' => '  Flat   3B ']), [['save']])->assertOk();

    $unit = unitsOf($this->acme)->sole();
    expect(LivewireHttp::errors($response))->toBe([])
        ->and(LivewireHttp::redirect($response))->toBe('http://localhost/o/acme-homes/buildings/palm-court')
        ->and($unit->unit_label)->toBe('Flat 3B')
        ->and($unit->property_id)->toBe($this->building->id)
        ->and($unit->unit_type)->toBe(UnitType::Apartment)
        ->and($unit->bedrooms)->toBe(3)
        ->and($unit->bathrooms)->toBe(2)
        ->and($unit->size_sqm)->toBe(120)
        ->and($unit->floor)->toBe('Ground')
        ->and($unit->status)->toBe(UnitStatus::Vacant)
        ->and($unit->description)->toBe('Corner flat with a balcony.');

    $entry = AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.created')->sole();
    expect($entry->subject_id)->toBe($unit->id)->and($entry->causer_id)->toBe($this->owner->id);
    Event::assertDispatched(UnitCreated::class, fn (UnitCreated $event): bool => $event->unitId === $unit->id && $event->propertyId === $this->building->id && $event->organizationId === $this->acme->id && ! $event->restored);

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('Flat 3B was added to Palm Court.')->assertSee('₦2,500,000 a year');
});

it('keeps rent and service charge as integer kobo through MoneyCast', function () {
    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/create'), unitForm(['baseRent' => '₦2,500,000', 'serviceCharge' => '250,000.50']), [['save']])->assertOk();

    $unit = unitsOf($this->acme)->sole();
    $raw = DB::table('units')->where('id', $unit->id)->first(['base_rent_kobo', 'service_charge_kobo']);

    expect((int) $raw->base_rent_kobo)->toBe(250_000_000)
        ->and((int) $raw->service_charge_kobo)->toBe(25_000_050)
        ->and($unit->base_rent_kobo)->toBeInstanceOf(Money::class)
        ->and($unit->base_rent_kobo->kobo)->toBe(250_000_000)
        ->and($unit->service_charge_kobo->format())->toBe('₦250,000.50')
        ->and($unit->getCasts()['base_rent_kobo'])->toBe(MoneyCast::class)
        ->and($unit->getCasts()['service_charge_kobo'])->toBe(MoneyCast::class);

    // Naira numbers are never stored as kobo by mistake.
    expect(fn () => $unit->base_rent_kobo = 2_500_000)->toThrow(InvalidArgumentException::class)
        ->and(fn () => $unit->service_charge_kobo = '250000')->toThrow(InvalidArgumentException::class);

    // The edit page shows naira again, and an empty service charge is none.
    $form = json_decode(unitsSnapshot($this->owner, 'palm-court', "/{$unit->id}/edit"), true);
    expect($form['data']['baseRent'])->toBe('2,500,000')->and($form['data']['serviceCharge'])->toBe('250,000.50');
    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$unit->id}/edit"), ['serviceCharge' => ''], [['save']])->assertOk();
    expect($unit->fresh()->service_charge_kobo->isZero())->toBeTrue();
});

it('refuses invalid details on the server, saving nothing', function (array $changes, string $field) {
    $response = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/create'), unitForm($changes), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(unitsOf($this->acme))->toHaveCount(0);
})->with([
    'no label' => [['unitLabel' => ' '], 'unitLabel'],
    'markup in the label' => [['unitLabel' => '<b>Flat</b>'], 'unitLabel'],
    'label too long' => [['unitLabel' => str_repeat('F', 61)], 'unitLabel'],
    'unknown type' => [['unitType' => 'castle'], 'unitType'],
    'bedrooms not a number' => [['bedrooms' => 'two'], 'bedrooms'],
    'too many bedrooms' => [['bedrooms' => '21'], 'bedrooms'],
    'no rent' => [['baseRent' => ''], 'baseRent'],
    'rent in words' => [['baseRent' => 'two million'], 'baseRent'],
    'negative rent' => [['baseRent' => '-1'], 'baseRent'],
    'huge rent' => [['baseRent' => '10,000,000,001'], 'baseRent'],
    'bad service charge' => [['serviceCharge' => '1.234'], 'serviceCharge'],
    'occupied by hand' => [['status' => 'occupied'], 'status'],
    'reserved by hand' => [['status' => 'reserved'], 'status'],
]);

it('gives a clear error for a label the building already has, and accepts it in another building', function () {
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens']);

    $response = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', '/create'), unitForm(['unitLabel' => 'FLAT 1']), [['save']])->assertOk();
    expect(LivewireHttp::errors($response)['unitLabel'] ?? [])->toBe(['Flat 1 already exists in Palm Court.'])
        ->and(unitsOf($this->acme))->toHaveCount(1);

    LivewireHttp::update($this, unitsSnapshot($this->owner, 'cedar-gardens', '/create'), unitForm(), [['save']])->assertOk();
    expect(unitsOf($this->acme)->pluck('property_id')->all())->toBe([$this->building->id, $cedar->id]);
});

it('edits a unit, audits what changed, and keeps labels unique in the building', function () {
    $unit = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2']);
    $archived = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 9']);
    inOrganization($this->acme, fn () => $archived->delete());
    $form = unitsSnapshot($this->owner, 'palm-court', "/{$unit->id}/edit");

    $taken = LivewireHttp::update($this, $form, ['unitLabel' => 'flat 2'], [['save']])->assertOk();
    $archivedLabel = LivewireHttp::update($this, $form, ['unitLabel' => 'Flat 9'], [['save']])->assertOk();
    expect(LivewireHttp::errors($taken)['unitLabel'] ?? [])->toBe(['Flat 2 already exists in Palm Court.'])
        ->and(LivewireHttp::errors($archivedLabel)['unitLabel'] ?? [])->toBe(['Flat 9 is an archived unit of Palm Court. Restore it instead, or choose another label.']);

    $saved = LivewireHttp::update($this, $form, ['unitLabel' => 'Flat 1A', 'status' => 'under_maintenance', 'baseRent' => '3,000,000'], [['save']])->assertOk();

    expect(LivewireHttp::redirect($saved))->toBe('http://localhost/o/acme-homes/buildings/palm-court');
    $fresh = $unit->fresh();
    expect($fresh->unit_label)->toBe('Flat 1A')
        ->and($fresh->status)->toBe(UnitStatus::UnderMaintenance)
        ->and($fresh->base_rent_kobo->equals(Money::naira(3_000_000)))->toBeTrue();

    $entry = AuditEntry::query()->where('event', 'unit.updated')->sole();
    expect($entry->properties['changed'])->toEqualCanonicalizing(['unit_label', 'base_rent_kobo', 'status'])
        ->and($entry->properties['status_from'])->toBe('vacant')
        ->and($entry->properties['status_to'])->toBe('under_maintenance');

    // Saving with nothing changed writes no audit entry.
    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$unit->id}/edit"), [], [['save']])->assertOk();
    expect(AuditEntry::query()->where('event', 'unit.updated')->count())->toBe(1);
});

it('leaves an occupied or reserved unit\'s status to its lease, and never archives it', function () {
    $occupied = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1', 'status' => UnitStatus::Occupied]);
    $reserved = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2', 'status' => UnitStatus::Reserved]);

    unitsPage($this->owner, 'palm-court', "/{$occupied->id}/edit")->assertOk()->assertSee('Set by the unit&#039;s lease.', false)->assertDontSee('data-test="unit-status"', false)->assertDontSee('data-test="archive-unit"', false);

    // A status sent anyway is refused; other details save.
    $form = unitsSnapshot($this->owner, 'palm-court', "/{$occupied->id}/edit");
    LivewireHttp::update($this, $form, ['status' => 'vacant', 'floor' => '2'], [['save']])->assertOk();
    expect($occupied->fresh()->status)->toBe(UnitStatus::Occupied)->and($occupied->fresh()->floor)->toBe('2');

    $edit = LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$reserved->id}/edit"), [], [['archive']])->assertOk();
    expect(LivewireHttp::html($edit))->toContain('Flat 2 is reserved. End its lease before archiving it.');
    $show = LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archiveUnit', [$occupied->id]]])->assertOk();
    expect(LivewireHttp::html($show))->toContain('Flat 1 is occupied. End its lease before archiving it.');

    expect(unitsOf($this->acme)->filter->trashed())->toHaveCount(0);
    buildingsPage($this->owner, '/palm-court')->assertOk()->assertDontSee('data-test="archive-unit-'.$occupied->id.'"', false);
});

it('archives a unit as a soft delete, so it leaves the counts and the bill, and restores it, all audited', function () {
    $unit = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2']);
    $deleted = [];
    $created = [];
    Event::listen(UnitDeleted::class, function (UnitDeleted $event) use (&$deleted): void {
        $deleted[] = $event;
    });
    Event::listen(UnitCreated::class, function (UnitCreated $event) use (&$created): void {
        $created[] = $event;
    });

    $show = buildingsSnapshot($this->owner, '/palm-court');
    $response = LivewireHttp::update($this, $show, [], [['archiveUnit', [$unit->id]]])->assertOk();

    expect(LivewireHttp::html($response))->toContain('Flat 1 was archived.')
        ->and(DB::table('units')->where('id', $unit->id)->value('deleted_at'))->not->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1)
        ->and($deleted)->toHaveCount(1)
        ->and($deleted[0]->unitId)->toBe($unit->id)
        ->and(AuditEntry::query()->where('event', 'unit.archived')->sole()->subject_id)->toBe($unit->id);

    buildingsPage($this->owner, '/palm-court')->assertOk()
        ->assertSee('0 of 1 unit occupied (0%)')->assertSee('Show 1 archived unit');
    unitsPage($this->owner, 'palm-court', "/{$unit->id}/edit")->assertNotFound();

    $show = LivewireHttp::next(LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), ['showArchivedUnits' => true])->assertOk());
    $restored = LivewireHttp::update($this, $show, [], [['restoreUnit', [$unit->id]]])->assertOk();

    expect(LivewireHttp::html($restored))->toContain('Flat 1 was restored.')
        ->and($unit->fresh()->trashed())->toBeFalse()
        ->and($created)->toHaveCount(1)
        ->and($created[0]->restored)->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'unit.restored')->sole()->subject_id)->toBe($unit->id);
});

it('drives the occupancy counts on the building page from the units\' statuses', function () {
    $units = inOrganization($this->acme, fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $this->building, bulkForm()));
    $directory = fn () => app(UnitDirectory::class);
    // Another building's and another organization's units never count here.
    unitIn($this->acme, buildingIn($this->acme, ['name' => 'Cedar Gardens']), ['status' => UnitStatus::Occupied]);
    unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court']), ['status' => UnitStatus::Occupied]);

    $counts = fn (): array => array_map(
        fn (string $status): string => trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/.*data-test="count-'.$status.'"[^>]*>(.*?)<\/button>.*/s', '$1', (string) buildingsPage($this->owner, '/palm-court')->getContent())))),
        ['all', 'vacant', 'reserved', 'occupied', 'under_maintenance', 'unavailable'],
    );

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('0 of 10 units occupied (0%)');
    expect($counts())->toBe(['All 10', 'Vacant 10', 'Reserved 0', 'Occupied 0', 'Under maintenance 0', 'Unavailable 0']);

    // Leasing sets occupied and reserved; people set the others.
    inOrganization($this->acme, function () use ($units, $directory): void {
        foreach (array_slice($units, 0, 6) as $unit) {
            $directory()->setStatus($unit->id, UnitDirectory::OCCUPIED, 'Lease activated');
        }
        $directory()->setStatus($units[6]->id, UnitDirectory::RESERVED, 'Draft lease');
    });
    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$units[7]->id}/edit"), ['status' => 'under_maintenance'], [['save']])->assertOk();
    LivewireHttp::update($this, unitsSnapshot($this->owner, 'palm-court', "/{$units[8]->id}/edit"), ['status' => 'unavailable'], [['save']])->assertOk();

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('6 of 10 units occupied (60%)');
    expect($counts())->toBe(['All 10', 'Vacant 1', 'Reserved 1', 'Occupied 6', 'Under maintenance 1', 'Unavailable 1']);

    // The board filters by status.
    $board = LivewireHttp::html(LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), ['boardStatus' => 'occupied'])->assertOk());
    expect(substr_count($board, 'data-status="occupied"'))->toBe(6)
        ->and(substr_count($board, 'data-status="vacant"'))->toBe(0);
    buildingsPage($this->owner, '/palm-court?status=reserved')->assertOk()->assertSee('data-unit="'.$units[6]->id.'"', false)->assertDontSee('data-unit="'.$units[0]->id.'"', false);

    // A lease ending frees the unit; archiving one takes it out of the counts.
    inOrganization($this->acme, fn () => $directory()->setStatus($units[0]->id, UnitDirectory::VACANT, 'Lease ended'));
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archiveUnit', [$units[9]->id]]])->assertOk();

    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('5 of 9 units occupied (55%)');
    expect($counts())->toBe(['All 9', 'Vacant 1', 'Reserved 1', 'Occupied 5', 'Under maintenance 1', 'Unavailable 1']);

    // The buildings list shows the same counts.
    buildingsPage($this->owner)->assertOk()->assertSeeText('9 units · 5 occupied');
});

it('refuses new units in an archived building, and keeps its units read-only', function () {
    $unit = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);
    $stale = $this->building->fresh();
    inOrganization($this->acme, fn () => $this->building->forceFill(['status' => PropertyStatus::Archived])->save());

    unitsPage($this->owner, 'palm-court', '/create')->assertForbidden();
    unitsPage($this->owner, 'palm-court', '/bulk')->assertForbidden();
    unitsPage($this->owner, 'palm-court', "/{$unit->id}/edit")->assertForbidden();
    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('Flat 1')
        ->assertDontSee('data-test="add-unit"', false)->assertDontSee('data-test="add-units-bulk"', false)
        ->assertDontSee('data-test="edit-unit-', false)->assertDontSee('data-test="archive-unit-', false);
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archiveUnit', [$unit->id]]])->assertForbidden();

    $archived = $this->building->fresh();
    inOrganization($this->acme, function () use ($archived): void {
        expect(fn () => app(Units::class)->create($this->owner, $archived, unitForm(['unitLabel' => 'Flat 2'])))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CreateUnitsInBulk::class)->handle($this->owner, $archived, bulkForm()))->toThrow(AuthorizationException::class);
    });

    // Archived after the page loaded the building: the locked row says no.
    inOrganization($this->acme, function () use ($stale): void {
        try {
            app(CreateUnitsInBulk::class)->handle($this->owner, $stale, bulkForm(['start' => '2']));
            $this->fail('Units were added to an archived building.');
        } catch (ValidationException $refused) {
            expect($refused->errors()['building'])->toBe(['Palm Court is archived. Restore it before adding units.']);
        }
    });

    expect(unitsOf($this->acme)->pluck('unit_label')->all())->toBe(['Flat 1']);
});

it('refuses to archive a building while a lease holds one of its units', function () {
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1', 'status' => UnitStatus::Occupied]);
    unitIn($this->acme, $this->building, ['unit_label' => 'Flat 2']);

    $show = LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archive']])->assertOk();
    $list = LivewireHttp::update($this, buildingsSnapshot($this->owner), [], [['archive', [$this->building->id]]])->assertOk();

    expect(LivewireHttp::html($show))->toContain('Palm Court has 1 occupied or reserved unit. End its lease before archiving the building.')
        ->and(LivewireHttp::html($list))->toContain('Palm Court has 1 occupied or reserved unit.')
        ->and($this->building->fresh()->status)->toBe(PropertyStatus::Active);

    // With no unit held, archiving goes through and leaves the units as they were.
    inOrganization($this->acme, fn () => app(UnitDirectory::class)->setStatus(unitsOf($this->acme)->first()->id, UnitDirectory::VACANT, 'Lease ended'));
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court'), [], [['archive']])->assertOk();
    expect($this->building->fresh()->status)->toBe(PropertyStatus::Archived)
        ->and(unitsOf($this->acme)->filter->trashed())->toHaveCount(0)
        ->and(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(0);
});

it('answers 404 for another organization\'s unit, or another building\'s, guessed by ID on every page and action', function () {
    $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
    $foreign = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Foreign 1']);
    $foreignArchived = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Foreign 2']);
    inOrganization($this->other, fn () => $foreignArchived->delete());
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens']);
    $cedarUnit = unitIn($this->acme, $cedar, ['unit_label' => 'Cedar 1']);
    $own = unitIn($this->acme, $this->building, ['unit_label' => 'Flat 1']);

    // Pages.
    unitsPage($this->owner, 'palm-court', "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'foreign-court', "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'foreign-court', '/create')->assertNotFound();
    unitsPage($this->owner, 'foreign-court', '/bulk')->assertNotFound();
    unitsPage($this->owner, 'palm-court', "/{$cedarUnit->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'palm-court', "/{$own->id}/edit")->assertOk();

    // Building page actions, by ID.
    $show = buildingsSnapshot($this->owner, '/palm-court');
    LivewireHttp::update($this, $show, [], [['archiveUnit', [$foreign->id]]])->assertNotFound();
    LivewireHttp::update($this, $show, [], [['archiveUnit', [$cedarUnit->id]]])->assertNotFound();
    LivewireHttp::update($this, $show, [], [['restoreUnit', [$foreignArchived->id]]])->assertNotFound();

    // The locked ids on the forms cannot be swapped (419 in production).
    config(['app.debug' => false]);
    $edit = json_decode(unitsSnapshot($this->owner, 'palm-court', "/{$own->id}/edit"), true);
    $edit['data']['unitId'] = $foreign->id;
    LivewireHttp::update($this, (string) json_encode($edit), [], [['save']])->assertStatus(419);
    $bulk = json_decode(unitsSnapshot($this->owner, 'palm-court', '/bulk'), true);
    $bulk['data']['propertyId'] = $foreignBuilding->id;
    LivewireHttp::update($this, (string) json_encode($bulk), bulkForm(), [['save']])->assertStatus(419);

    // A member of both organizations, inside Acme's workspace, still gets 404.
    joinAs($this->owner, $this->other);
    unitsPage($this->owner, 'foreign-court', "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'foreign-court', "/{$foreign->id}/edit", 'other-realty')->assertOk();

    $foreignNow = unitsOf($this->other);
    expect($foreignNow)->toHaveCount(2)
        ->and($foreignNow->firstWhere('id', $foreign->id)->trashed())->toBeFalse()
        ->and($foreignNow->firstWhere('id', $foreignArchived->id)->trashed())->toBeTrue()
        ->and(unitsOf($this->acme))->toHaveCount(2);
});
