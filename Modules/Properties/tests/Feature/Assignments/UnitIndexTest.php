<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/*
 * P02-03: the unit list, /o/{slug}/units (ARCHITECTURE.md § 13: unit lists
 * paginated on the server, debounced search, no N+1), and the AC "a unit
 * of org B is not addressable by an org A user via ID guessing" on every
 * unit and building page and action.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
});

afterEach(fn () => freshRequest());

it('lists every unit across the buildings, 25 a page on the server, with filters and a debounced search kept in the URL', function () {
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens']);
    inOrganization($this->acme, function () use ($cedar): void {
        foreach (range(1, 20) as $i) {
            Unit::factory()->in($this->palm)->create(['unit_label' => "Flat {$i}", 'status' => $i <= 4 ? UnitStatus::Occupied : UnitStatus::Vacant]);
        }
        foreach (range(1, 10) as $i) {
            Unit::factory()->in($cedar)->create(['unit_label' => "Shop {$i}", 'unit_type' => UnitType::Shop]);
        }
    });

    $page = unitListPage($this->owner)->assertOk()
        ->assertSee('wire:model.live.debounce.400ms="search"', false)
        ->assertSee('data-test="units-list"', false);
    $first = listedUnits($page);
    expect($first)->toHaveCount(25);
    // By building name, then label: Cedar Gardens' shops first, Shop 2 before Shop 10.
    $labels = inOrganization($this->acme, fn () => Unit::query()->whereKey($first)->pluck('unit_label', 'id'));
    expect(array_map(fn (int $id): string => $labels[$id], array_slice($first, 0, 12)))
        ->toBe(['Shop 1', 'Shop 2', 'Shop 3', 'Shop 4', 'Shop 5', 'Shop 6', 'Shop 7', 'Shop 8', 'Shop 9', 'Shop 10', 'Flat 1', 'Flat 2']);
    expect(listedUnits(unitListPage($this->owner, '?page=2')))->toHaveCount(5)
        ->each->not->toBeIn($first);

    // Filters and search, each from the URL and from the page.
    expect(listedUnits(unitListPage($this->owner, "?building={$cedar->id}")))->toHaveCount(10)
        ->and(listedUnits(unitListPage($this->owner, '?status=occupied')))->toHaveCount(4)
        ->and(listedUnits(unitListPage($this->owner, '?type=shop&q=shop 1')))->toHaveCount(2)
        ->and(listedUnits(unitListPage($this->owner, '?q=cedar')))->toHaveCount(10)
        ->and(listedUnits(unitListPage($this->owner, '?status=nonsense')))->toHaveCount(25);

    $list = unitListSnapshot($this->owner, '?page=2');
    $response = LivewireHttp::update($this, $list, ['search' => 'Flat 1'])->assertOk();
    // A new search starts again on page 1: Flat 1 and Flat 10–19.
    expect(listedUnits($response))->toHaveCount(11);
    $response = LivewireHttp::update($this, LivewireHttp::next($response), ['status' => 'occupied'])->assertOk();
    expect(listedUnits($response))->toHaveCount(1);
    $response = LivewireHttp::update($this, LivewireHttp::next($response), ['search' => 'nothing like it'])->assertOk();
    expect(htmlOf($response))->toContain('No unit matches');
    $response = LivewireHttp::update($this, LivewireHttp::next($response), [], [['clearFilters']])->assertOk();
    expect(listedUnits($response))->toHaveCount(25);
});

it('loads the unit list without a query per unit or building (no N+1)', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $count = function (User $user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        unitListPage($user)->assertOk();
        DB::disableQueryLog();

        // Only the list's own tables: sessions, caches and permission lookups vary with what earlier requests warmed.
        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/`(units|properties|agent_building)`/', $query['query']) === 1));
    };

    unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    assignIn($this->acme, $agent, $this->palm);
    $few = $count($this->owner);
    $fewNarrowed = $count($agent);

    // Many units across many buildings (one archived), all assigned to the agent.
    inOrganization($this->acme, function () use ($agent): void {
        foreach (range(1, 12) as $b) {
            $building = Property::factory()->create(['status' => $b === 12 ? PropertyStatus::Archived : PropertyStatus::Active]);
            Unit::factory()->count(5)->in($building)->create();
            AgentAssignment::factory()->of($agent, $building)->create();
        }
    });

    expect(listedUnits(unitListPage($this->owner)))->toHaveCount(25)
        ->and($count($this->owner))->toBe($few)
        ->and($count($agent))->toBe($fewNarrowed);
});

it('never shows or reaches a unit of another organization guessed by ID, on the unit list or any unit or building page and action', function () {
    $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
    $foreign = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Foreign 1']);
    $foreignArchived = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Foreign 2']);
    inOrganization($this->other, fn () => $foreignArchived->delete());
    $own = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);

    // The unit list: never in it, not by a guessed building id, nor by a search.
    expect(listedUnits(unitListPage($this->owner)))->toBe([$own->id])
        ->and(listedUnits(unitListPage($this->owner, "?building={$foreignBuilding->id}")))->toBe([])
        ->and(listedUnits(unitListPage($this->owner, '?q=Foreign')))->toBe([]);
    $list = unitListSnapshot($this->owner);
    expect(listedUnits(LivewireHttp::update($this, $list, ['building' => (string) $foreignBuilding->id])->assertOk()))->toBe([]);
    expect(htmlOf(unitListPage($this->owner)))->not->toContain('Foreign Court');

    // Every unit and building page, by guessed ID or slug.
    unitsPage($this->owner, 'palm-court', "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'foreign-court', "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, (string) $foreignBuilding->id, "/{$foreign->id}/edit")->assertNotFound();
    unitsPage($this->owner, 'foreign-court', '/create')->assertNotFound();
    unitsPage($this->owner, 'foreign-court', '/bulk')->assertNotFound();
    buildingsPage($this->owner, '/foreign-court')->assertNotFound();
    buildingsPage($this->owner, '/foreign-court/edit')->assertNotFound();
    buildingsPage($this->owner, "/{$foreignBuilding->id}")->assertNotFound();

    // Every action that takes an ID.
    $show = buildingsSnapshot($this->owner, '/palm-court');
    LivewireHttp::update($this, $show, [], [['archiveUnit', [$foreign->id]]])->assertNotFound();
    LivewireHttp::update($this, $show, [], [['restoreUnit', [$foreignArchived->id]]])->assertNotFound();
    LivewireHttp::update($this, $show, [], [['unassignAgent', [$this->owner->id]]])->assertNotFound();
    $buildings = buildingsSnapshot($this->owner);
    LivewireHttp::update($this, $buildings, [], [['archive', [$foreignBuilding->id]]])->assertNotFound();
    LivewireHttp::update($this, $buildings, [], [['restore', [$foreignBuilding->id]]])->assertNotFound();

    // Locked ids swapped on the forms and the building page (419 in production).
    config(['app.debug' => false]);
    $edit = json_decode(unitsSnapshot($this->owner, 'palm-court', "/{$own->id}/edit"), true);
    $edit['data']['unitId'] = $foreign->id;
    LivewireHttp::update($this, (string) json_encode($edit), unitForm(), [['save']])->assertStatus(419);
    $page = json_decode($show, true);
    $page['data']['propertyId'] = $foreignBuilding->id;
    LivewireHttp::update($this, (string) json_encode($page), ['agentToAssign' => (string) $this->owner->id], [['assignAgent']])->assertStatus(419);

    // A member of both organizations, inside Acme's workspace, still gets 404 and an Acme-only list.
    joinAs($this->owner, $this->other);
    unitsPage($this->owner, 'foreign-court', "/{$foreign->id}/edit")->assertNotFound();
    expect(listedUnits(unitListPage($this->owner)))->toBe([$own->id])
        ->and(listedUnits(unitListPage($this->owner, '', 'other-realty')))->toBe([$foreign->id]);

    $foreignNow = unitsOf($this->other);
    expect($foreignNow)->toHaveCount(2)
        ->and($foreignNow->firstWhere('id', $foreign->id)->unit_label)->toBe('Foreign 1')
        ->and($foreignNow->firstWhere('id', $foreign->id)->trashed())->toBeFalse()
        ->and($foreignNow->firstWhere('id', $foreignArchived->id)->trashed())->toBeTrue()
        ->and(inOrganization($this->other, fn () => Property::query()->find($foreignBuilding->id)?->status))->toBe(PropertyStatus::Active);
});

it('opens the unit list for workspace roles only, with edit links only where the member may edit', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $unit = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $old = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'status' => PropertyStatus::Archived]);
    $oldUnit = unitIn($this->acme, $old, ['unit_label' => 'Old 1']);
    // A building an import rollback removed: its units are not listed.
    $removed = buildingIn($this->acme, ['name' => 'Removed Court', 'slug' => 'removed-court']);
    unitIn($this->acme, $removed, ['unit_label' => 'Removed 1']);
    inOrganization($this->acme, fn () => $removed->delete());

    foreach ([$this->owner, $admin, $agent] as $user) {
        $page = unitListPage($user)->assertOk()->assertSee('data-test="edit-unit-'.$unit->id.'"', false)
            // Units of an archived building are read-only.
            ->assertDontSee('data-test="edit-unit-'.$oldUnit->id.'"', false)->assertSee('archived building');
        expect(listedUnits($page))->toBe([$oldUnit->id, $unit->id]);
    }

    foreach ([OrganizationRole::PropertyOwner, OrganizationRole::Tenant] as $role) {
        unitListPage(joinAs(User::factory()->create(), $this->acme, $role))->assertNotFound();
    }
    unitListPage(User::factory()->create())->assertNotFound();
    unitListPage(joinAs(User::factory()->create(), $this->other))->assertNotFound();
    $this->get('/o/acme-homes/units')->assertNotFound();
    auth()->logout();
    $this->get('/o/acme-homes/units')->assertRedirect();
});

it('shows the unit list read-only while the organization is suspended', function () {
    $unit = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $list = unitListSnapshot($this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expect(listedUnits(unitListPage($this->owner)->assertOk()->assertDontSee('data-test="edit-unit-', false)))->toBe([$unit->id]);
    // Filters and paging still work: they are reads.
    expect(listedUnits(LivewireHttp::update($this, $list, ['status' => 'vacant'])->assertOk()))->toBe([$unit->id]);
    LivewireHttp::update($this, $list, [], [['clearFilters']])->assertForbidden();
});

it('shows impersonating staff the unit list, read-only in a read-only session', function () {
    $unit = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::ReadOnly))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get('/o/acme-homes/units')->assertOk()->assertDontSee('data-test="edit-unit-', false);
    expect(listedUnits($page))->toBe([$unit->id]);
});
