<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Buildings\Properties;
use Modules\Properties\Buildings\PropertyPhotos;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\Unit;
use Modules\Properties\Units\Units;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-03 AC: a narrowed agent sees only assigned buildings
 * (ARCHITECTURE.md § 5: "`agent` visibility can be narrowed by building
 * assignment"). An agent with at least one assignment sees and works on
 * those buildings, their units and their owners only, on every list
 * (buildings, units, owners, the status board, the directories other
 * modules use) and page; any other building, unit or owner answers 404.
 * Narrowing is optional, per agent: an agent with no assignment, and every
 * owner and administrator, sees every building.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Ada Agent']), $this->acme, OrganizationRole::Agent);

    $this->tunde = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $this->kemi = ownerIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adeyemi']);
    // Assigned: Palm Court (Tunde's) and Old Yard (archived, Tunde's). Not assigned: Cedar Gardens (Kemi's), Lagoon View (Tunde's).
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'owner_profile_id' => $this->tunde->id]);
    $this->old = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'owner_profile_id' => $this->tunde->id, 'status' => PropertyStatus::Archived]);
    $this->cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens', 'owner_profile_id' => $this->kemi->id]);
    $this->lagoon = buildingIn($this->acme, ['name' => 'Lagoon View', 'slug' => 'lagoon-view', 'owner_profile_id' => $this->tunde->id]);

    $this->palm1 = unitIn($this->acme, $this->palm, ['unit_label' => 'Palm 1']);
    $this->palm2 = unitIn($this->acme, $this->palm, ['unit_label' => 'Palm 2', 'status' => UnitStatus::Occupied]);
    $this->old1 = unitIn($this->acme, $this->old, ['unit_label' => 'Old 1']);
    $this->cedar1 = unitIn($this->acme, $this->cedar, ['unit_label' => 'Cedar 1']);
    $this->cedarArchived = unitIn($this->acme, $this->cedar, ['unit_label' => 'Cedar 2']);
    inOrganization($this->acme, fn () => $this->cedarArchived->delete());
    $this->lagoon1 = unitIn($this->acme, $this->lagoon, ['unit_label' => 'Lagoon 1']);

    assignIn($this->acme, $this->agent, $this->palm);
    assignIn($this->acme, $this->agent, $this->old);
});

afterEach(fn () => freshRequest());

it('shows a narrowed agent only the assigned buildings, on every list and page', function () {
    // Buildings list: active and archived.
    expect(listedBuildings(buildingsPage($this->agent)->assertOk()))->toBe([$this->palm->id])
        ->and(listedBuildings(buildingsPage($this->agent, '?archived=1')->assertOk()))->toBe([$this->old->id]);
    $list = buildingsSnapshot($this->agent);
    expect(listedBuildings(LivewireHttp::update($this, $list, ['search' => 'a'])->assertOk()))->toBe([$this->palm->id])
        ->and(listedBuildings(LivewireHttp::update($this, $list, ['owner' => (string) $this->kemi->id])->assertOk()))->toBe([]);
    // The owner filter offers only the owners of their buildings.
    expect(htmlOf(buildingsPage($this->agent)))->toContain('>Tunde Bakare</option>')->not->toContain('>Kemi Adeyemi</option>');

    // Building pages: their own open (the archived one read-only), the others 404.
    buildingsPage($this->agent, '/palm-court')->assertOk()->assertSee('Palm 1')->assertSee('Palm 2');
    buildingsPage($this->agent, '/old-yard')->assertOk()->assertSee('data-test="archived-banner"', false);
    foreach (['/cedar-gardens', '/lagoon-view', '/cedar-gardens/edit', '/lagoon-view/edit'] as $path) {
        buildingsPage($this->agent, $path)->assertNotFound();
    }
    buildingsPage($this->agent, '/palm-court/edit')->assertOk();

    // The status board shows the building's units, filtered too.
    $board = buildingsSnapshot($this->agent, '/palm-court');
    expect(listedUnits(buildingsPage($this->agent, '/palm-court')))->toEqualCanonicalizing([$this->palm1->id, $this->palm2->id])
        ->and(listedUnits(LivewireHttp::update($this, $board, ['boardStatus' => 'occupied'])->assertOk()))->toBe([$this->palm2->id]);

    // Unit pages: their buildings' units only.
    unitsPage($this->agent, 'palm-court', "/{$this->palm1->id}/edit")->assertOk();
    unitsPage($this->agent, 'cedar-gardens', "/{$this->cedar1->id}/edit")->assertNotFound();
    unitsPage($this->agent, 'lagoon-view', "/{$this->lagoon1->id}/edit")->assertNotFound();
    unitsPage($this->agent, 'palm-court', "/{$this->cedar1->id}/edit")->assertNotFound();

    // The unit list: their units, their buildings in the filter, nothing by a guessed building id.
    expect(listedUnits(unitListPage($this->agent)->assertOk()))->toBe([$this->old1->id, $this->palm1->id, $this->palm2->id]);
    $units = htmlOf(unitListPage($this->agent));
    expect($units)->toContain('>Palm Court</option>')->toContain('>Old Yard</option>')
        ->not->toContain('>Cedar Gardens</option>')->not->toContain('>Lagoon View</option>')->not->toContain('Cedar 1');
    expect(listedUnits(unitListPage($this->agent, "?building={$this->cedar->id}")->assertOk()))->toBe([])
        ->and(listedUnits(unitListPage($this->agent, '?q=Cedar')->assertOk()))->toBe([])
        ->and(listedUnits(unitListPage($this->agent, '?status=vacant')->assertOk()))->toBe([$this->old1->id, $this->palm1->id]);

    // Owners: those of their buildings only; the page lists only their buildings of that owner.
    $owners = htmlOf(test()->actingAs($this->agent)->get('/o/acme-homes/owners')->assertOk());
    expect($owners)->toContain('Tunde Bakare')->not->toContain('Kemi Adeyemi');
    test()->actingAs($this->agent)->get("/o/acme-homes/owners/{$this->kemi->id}")->assertNotFound();
    $tunde = htmlOf(test()->actingAs($this->agent)->get("/o/acme-homes/owners/{$this->tunde->id}")->assertOk());
    expect($tunde)->toContain('Palm Court')->toContain('Old Yard')->not->toContain('Lagoon View');

    // Owners and administrators still see everything.
    expect(listedBuildings(buildingsPage($this->owner)))->toEqualCanonicalizing([$this->palm->id, $this->cedar->id, $this->lagoon->id])
        ->and(listedUnits(unitListPage($this->owner)))->toHaveCount(5);
});

it('answers 404 to a narrowed agent for other buildings\' actions sent straight to the server', function () {
    // Pages loaded by the owner, replayed by the agent.
    $cedarPage = buildingsSnapshot($this->owner, '/cedar-gardens');
    $cedarEdit = buildingsSnapshot($this->owner, '/cedar-gardens/edit');
    $cedarUnit = unitsSnapshot($this->owner, 'cedar-gardens', "/{$this->cedar1->id}/edit");
    $this->actingAs($this->agent);

    LivewireHttp::update($this, $cedarPage)->assertNotFound();
    LivewireHttp::update($this, $cedarPage, [], [['makeCover', [1]]])->assertNotFound();
    LivewireHttp::update($this, $cedarEdit, buildingForm(['name' => 'Taken Over']), [['save']])->assertNotFound();
    LivewireHttp::update($this, $cedarUnit, unitForm(['unitLabel' => 'Taken 1']), [['save']])->assertNotFound();

    // From their own building's page, another building's unit is not found either.
    $palmPage = buildingsSnapshot($this->agent, '/palm-court');
    LivewireHttp::update($this, $palmPage, [], [['archiveUnit', [$this->cedar1->id]]])->assertNotFound();
    LivewireHttp::update($this, $palmPage, [], [['restoreUnit', [$this->cedarArchived->id]]])->assertNotFound();

    // The writers refuse them too (404 status on the refusal).
    $refusals = [
        fn () => app(Properties::class)->update($this->agent, $this->cedar, buildingForm(['name' => 'Taken Over'])),
        fn () => app(Units::class)->update($this->agent, $this->cedar1, unitForm(['unitLabel' => 'Taken 1'])),
        fn () => app(PropertyPhotos::class)->makeCover($this->agent, $this->cedar, 1),
    ];
    foreach ($refusals as $refusal) {
        try {
            inOrganization($this->acme, $refusal);
            $this->fail('The writer did not refuse.');
        } catch (AuthorizationException $refused) {
            expect($refused->status())->toBe(404);
        }
    }

    expect(inOrganization($this->acme, fn () => $this->cedar->fresh()->name))->toBe('Cedar Gardens')
        ->and(inOrganization($this->acme, fn () => $this->cedar1->fresh()->unit_label))->toBe('Cedar 1');

    // Their own building still saves.
    LivewireHttp::update($this, unitsSnapshot($this->agent, 'palm-court', "/{$this->palm1->id}/edit"), ['floor' => '2'], [['save']])->assertOk();
    expect(inOrganization($this->acme, fn () => $this->palm1->fresh()->floor))->toBe('2');
});

it('answers the narrowed agent\'s policy checks per building, with 404 outside the assignments', function () {
    inOrganization($this->acme, function (): void {
        $gate = Gate::forUser($this->agent);

        expect($gate->allows('view', $this->palm))->toBeTrue()
            ->and($gate->allows('update', $this->palm))->toBeTrue()
            ->and($gate->allows('addPhotos', $this->palm))->toBeTrue()
            ->and($gate->allows('view', $this->old))->toBeTrue()
            ->and($gate->allows('update', $this->old))->toBeFalse()
            ->and($gate->allows('view', $this->palm1))->toBeTrue()
            ->and($gate->allows('update', $this->palm1))->toBeTrue()
            ->and($gate->allows('view', $this->tunde))->toBeTrue();

        foreach ([['view', $this->cedar], ['update', $this->cedar], ['addPhotos', $this->cedar], ['deletePhoto', $this->cedar], ['view', $this->cedar1], ['update', $this->cedar1], ['view', $this->lagoon1], ['view', $this->kemi]] as [$ability, $record]) {
            expect($gate->inspect($ability, $record)->status())->toBe(404, $ability.' '.$record::class);
        }
        expect($gate->inspect('create', [Unit::class, $this->cedar])->status())->toBe(404);
    });
});

it('narrows the directories other modules show to the member', function () {
    inOrganization($this->acme, function (): void {
        expect(app(BuildingAccess::class)->limitedTo($this->agent))->toBe([$this->palm->id, $this->old->id])
            ->and(app(BuildingAccess::class)->allows($this->agent, $this->cedar->id))->toBeFalse()
            ->and(app(BuildingAccess::class)->limitedTo($this->owner))->toBeNull()
            ->and(app(PropertyDirectory::class)->activeOptions($this->agent))->toBe([$this->palm->id => 'Palm Court'])
            ->and(app(PropertyDirectory::class)->activeOptions())->toBe([$this->cedar->id => 'Cedar Gardens', $this->lagoon->id => 'Lagoon View', $this->palm->id => 'Palm Court'])
            ->and(app(UnitDirectory::class)->counts($this->agent)->total())->toBe(2)
            ->and(app(UnitDirectory::class)->counts($this->agent)->occupied)->toBe(1)
            ->and(app(UnitDirectory::class)->counts()->total())->toBe(4)
            // The GoProperty bill is the organization's: never narrowed.
            ->and(app(UnitDirectory::class)->countBillable())->toBe(4);
    });
});

it('offers a narrowed agent only their buildings\' owners on the building form, and refuses any other', function () {
    $form = buildingsSnapshot($this->agent, '/palm-court/edit');
    expect(htmlOf(buildingsPage($this->agent, '/palm-court/edit')))->toContain('Tunde Bakare')->not->toContain('Kemi Adeyemi');

    $response = LivewireHttp::update($this, $form, buildingForm(['name' => 'Palm Court', 'ownerProfileId' => (string) $this->kemi->id]), [['save']])->assertOk();
    expect(LivewireHttp::errors($response)['ownerProfileId'] ?? [])->toBe(['Choose an owner from the list.'])
        ->and(inOrganization($this->acme, fn () => $this->palm->fresh()->owner_profile_id))->toBe($this->tunde->id);

    // An owner or administrator may choose any owner.
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/palm-court/edit'), buildingForm(['name' => 'Palm Court', 'ownerProfileId' => (string) $this->kemi->id]), [['save']])->assertOk();
    expect(inOrganization($this->acme, fn () => $this->palm->fresh()->owner_profile_id))->toBe($this->kemi->id);
});

it('lets an agent with no assignment see every building, as narrowing is optional per agent', function () {
    $free = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    expect(listedBuildings(buildingsPage($free)))->toEqualCanonicalizing([$this->palm->id, $this->cedar->id, $this->lagoon->id])
        ->and(listedUnits(unitListPage($free)))->toHaveCount(5);
    buildingsPage($free, '/cedar-gardens')->assertOk();
    test()->actingAs($free)->get("/o/acme-homes/owners/{$this->kemi->id}")->assertOk();

    // Taken off their last building, the narrowed agent sees every building again.
    inOrganization($this->acme, fn () => AgentAssignment::query()->where('user_id', $this->agent->id)->delete());
    expect(listedBuildings(buildingsPage($this->agent)))->toEqualCanonicalizing([$this->palm->id, $this->cedar->id, $this->lagoon->id]);
});

it('never narrows owners or administrators, even with assignments left from when they were agents', function () {
    inOrganization($this->acme, fn () => $this->agent->assignRole(OrganizationRole::Admin->value));

    expect(listedBuildings(buildingsPage($this->agent)))->toEqualCanonicalizing([$this->palm->id, $this->cedar->id, $this->lagoon->id]);
    buildingsPage($this->agent, '/cedar-gardens')->assertOk();

    // Back to agent only: the assignments apply again.
    inOrganization($this->acme, fn () => $this->agent->removeRole(OrganizationRole::Admin->value));
    buildingsPage($this->agent, '/cedar-gardens')->assertNotFound();
});

it('counts only the current organization\'s assignments', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    joinAs($this->agent, $other, OrganizationRole::Agent);
    $foreign = buildingIn($other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
    $foreignToo = buildingIn($other, ['name' => 'Foreign Two', 'slug' => 'foreign-two']);

    // No assignment in Other Realty: every building there, though Acme narrows them.
    expect(listedBuildings(buildingsPage($this->agent, '', 'other-realty')))->toEqualCanonicalizing([$foreign->id, $foreignToo->id]);

    assignIn($other, $this->agent, $foreign);
    expect(listedBuildings(buildingsPage($this->agent, '', 'other-realty')))->toBe([$foreign->id])
        ->and(listedBuildings(buildingsPage($this->agent)))->toBe([$this->palm->id]);
});

it('narrows impersonating staff exactly like the agent they act as', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->agent, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    expect(listedBuildings(asSupport()->get('/o/acme-homes/buildings')->assertOk()))->toBe([$this->palm->id])
        ->and(listedUnits(asSupport()->get('/o/acme-homes/units')->assertOk()))->toBe([$this->old1->id, $this->palm1->id, $this->palm2->id]);
    asSupport()->get('/o/acme-homes/buildings/palm-court')->assertOk();
    asSupport()->get('/o/acme-homes/buildings/cedar-gardens')->assertNotFound();
    asSupport()->get("/o/acme-homes/buildings/cedar-gardens/units/{$this->cedar1->id}/edit")->assertNotFound();
    asSupport()->get("/o/acme-homes/buildings/palm-court/units/{$this->palm1->id}/edit")->assertOk();
});

it('keeps the narrowing while the organization is suspended', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expect(listedBuildings(buildingsPage($this->agent)->assertOk()))->toBe([$this->palm->id])
        ->and(listedUnits(unitListPage($this->agent)->assertOk()))->toBe([$this->old1->id, $this->palm1->id, $this->palm2->id]);
    buildingsPage($this->agent, '/cedar-gardens')->assertNotFound();
    buildingsPage($this->agent, '/palm-court')->assertOk()->assertDontSee('data-test="edit-unit-', false);
});

it('shuts the building page on a narrowed agent who is taken off it while it is open', function () {
    $page = buildingsSnapshot($this->agent, '/palm-court');
    inOrganization($this->acme, fn () => AgentAssignment::query()->where('property_id', $this->palm->id)->delete());

    LivewireHttp::update($this, $page, ['boardStatus' => 'occupied'])->assertNotFound();
});
