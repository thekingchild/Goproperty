<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\ChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Livewire\PropertyIndex;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P02-01b: the Buildings pages (/o/{slug}/buildings): add, open, edit,
 * archive and restore a building; slugs unique per organization; the owner
 * picker; list search, filters and paging; another organization's
 * buildings unreachable by slug or ID.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('adds a building from the page with its owner and amenities, audits it, and ticks the setup checklist', function () {
    inOrganization($this->acme, fn () => app(OnboardingChecklist::class)->start());
    $landlord = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $snapshot = buildingsSnapshot($this->owner, '/create');

    $response = LivewireHttp::update($this, $snapshot, buildingForm(['ownerProfileId' => (string) $landlord->id, 'name' => '  Palm   Court ']), [['save']])->assertOk();

    $building = buildingsOf($this->acme)->sole();
    expect(LivewireHttp::redirect($response))->toBe('http://localhost/o/acme-homes/buildings/palm-court')
        ->and($building->name)->toBe('Palm Court')
        ->and($building->slug)->toBe('palm-court')
        ->and($building->property_type)->toBe(PropertyType::BlockOfFlats)
        ->and($building->status)->toBe(PropertyStatus::Active)
        ->and($building->owner_profile_id)->toBe($landlord->id)
        ->and($building->state)->toBe('Lagos')
        ->and($building->year_built)->toBe(2015)
        // Suggested amenities first, duplicates (any case) dropped.
        ->and($building->amenities)->toBe(['Borehole', 'Security', 'Rooftop terrace'])
        ->and($building->organization_id)->toBe($this->acme->id);

    $entry = AuditEntry::query()->where('log_name', 'properties')->sole();
    expect($entry->event)->toBe('property.created')
        ->and($entry->subject_id)->toBe($building->id)
        ->and($entry->causer_id)->toBe($this->owner->id);

    expect(inOrganization($this->acme, fn () => app(OnboardingChecklist::class)->isDone(ChecklistItem::Data)))->toBeTrue();

    buildingsPage($this->owner, '/palm-court')->assertOk()
        ->assertSee('Palm Court')->assertSee('Tunde Bakare')->assertSee('Rooftop terrace')->assertSee('Opposite Mega Plaza');
    buildingsPage($this->owner)->assertOk()->assertSee('Palm Court')->assertSee('Owner: Tunde Bakare');
});

it('refuses invalid details on the server, saving nothing', function (array $changes, string $field) {
    $snapshot = buildingsSnapshot($this->owner, '/create');

    $response = LivewireHttp::update($this, $snapshot, buildingForm($changes), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(buildingsOf($this->acme))->toHaveCount(0);
})->with([
    'no name' => [['name' => ' '], 'name'],
    'markup in the name' => [['name' => '<b>Palm</b>'], 'name'],
    'unknown type' => [['propertyType' => 'castle'], 'propertyType'],
    'no address' => [['address' => ''], 'address'],
    'no state' => [['state' => ''], 'state'],
    'unknown state' => [['state' => 'Atlantis'], 'state'],
    'unknown owner' => [['ownerProfileId' => '999999'], 'ownerProfileId'],
    'year in the future' => [['yearBuilt' => '2999'], 'yearBuilt'],
    'year not a number' => [['yearBuilt' => 'old'], 'yearBuilt'],
    'amenity too long' => [['otherAmenities' => str_repeat('a', 41)], 'amenities.2'],
    'markup in an amenity' => [['otherAmenities' => '<script>x</script>'], 'amenities.2'],
    'description too long' => [['description' => str_repeat('a', 2001)], 'description'],
]);

it('gives each building a slug unique in its organization, and keeps it when the building is renamed', function () {
    foreach (['Palm Court', 'Palm Court', 'Create', '!!!'] as $name) {
        LivewireHttp::update($this, buildingsSnapshot($this->owner, '/create'), buildingForm(['name' => $name]), [['save']])->assertOk();
    }

    // A building removed by an import rollback (soft deleted) still holds its slug.
    inOrganization($this->acme, fn () => Property::query()->where('slug', 'palm-court-2')->firstOrFail()->delete());
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/create'), buildingForm(), [['save']])->assertOk();

    expect(buildingsOf($this->acme)->pluck('slug')->all())->toBe(['palm-court', 'palm-court-2', 'create-2', 'building', 'palm-court-3']);

    // Another organization's slugs are its own.
    $otherOwner = joinAs(User::factory()->create(), $this->other);
    LivewireHttp::update($this, buildingsSnapshot($otherOwner, '/create', 'other-realty'), buildingForm(), [['save']])->assertOk();
    expect(buildingsOf($this->other)->sole()->slug)->toBe('palm-court');

    // "create" is the add page, never a building.
    buildingsPage($this->owner, '/create')->assertOk()->assertSee('Add building');
    buildingsPage($this->owner, '/create-2')->assertOk()->assertSee('Create');

    // Renaming keeps the slug, so links keep working.
    $form = buildingsSnapshot($this->owner, '/palm-court/edit');
    $response = LivewireHttp::update($this, $form, ['name' => 'Palm Court Annex'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($response))->toBe('http://localhost/o/acme-homes/buildings/palm-court');

    $renamed = buildingsOf($this->acme)->firstWhere('slug', 'palm-court');
    expect($renamed->name)->toBe('Palm Court Annex')
        ->and(AuditEntry::query()->where('event', 'property.updated')->sole()->properties->get('changed'))->toBe(['name']);
});

it('edits a building, auditing the fields that changed', function () {
    $building = buildingIn($this->acme, ['name' => 'Unity Towers', 'slug' => 'unity-towers', 'amenities' => ['Generator', 'Gas plant']]);
    $form = buildingsSnapshot($this->owner, '/unity-towers/edit');

    expect($form)->toContain('Gas plant');

    LivewireHttp::update($this, $form, ['propertyType' => 'mixed', 'amenities' => ['Generator', 'CCTV'], 'landmark' => 'By the filling station'], [['save']])->assertOk();

    $fresh = $building->fresh();
    expect($fresh->property_type)->toBe(PropertyType::Mixed)
        ->and($fresh->amenities)->toBe(['Generator', 'CCTV', 'Gas plant'])
        ->and(AuditEntry::query()->where('event', 'property.updated')->sole()->properties->get('changed'))->toBe(['property_type', 'landmark', 'amenities']);
});

it('picks the owner from the organization\'s owners only, and keeps an owner archived since', function () {
    $foreignOwner = ownerIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Owner']);
    $current = ownerIn($this->acme, ['first_name' => 'Kept', 'last_name' => 'Owner']);
    $archivedOwner = ownerIn($this->acme, ['first_name' => 'Gone', 'last_name' => 'Owner']);
    $building = buildingIn($this->acme, ['slug' => 'palm-court', 'owner_profile_id' => $current->id]);
    inOrganization($this->acme, fn () => $current->delete());
    inOrganization($this->acme, fn () => $archivedOwner->delete());

    // The form offers the archived current owner (marked), not another archived one or a foreign one.
    $page = buildingsPage($this->owner, '/palm-court/edit')->assertOk()->assertSee('Kept Owner (archived)');
    expect((string) $page->getContent())->not->toContain('Gone Owner')->not->toContain('Foreign Owner');

    $form = LivewireHttp::snapshot($page);
    expect(LivewireHttp::errors(LivewireHttp::update($this, $form, ['ownerProfileId' => (string) $foreignOwner->id], [['save']])))->toHaveKey('ownerProfileId')
        ->and(LivewireHttp::errors(LivewireHttp::update($this, $form, ['ownerProfileId' => (string) $archivedOwner->id], [['save']])))->toHaveKey('ownerProfileId');

    // Saving other changes keeps the archived owner; "none" removes it.
    LivewireHttp::update($this, $form, ['description' => 'Repainted.'], [['save']])->assertOk();
    expect($building->fresh()->owner_profile_id)->toBe($current->id);

    LivewireHttp::update($this, $form, ['ownerProfileId' => ''], [['save']])->assertOk();
    expect($building->fresh()->owner_profile_id)->toBeNull();
});

it('archives a building as a status change, never a delete, keeps it readable, and restores it, all audited', function () {
    $building = buildingIn($this->acme, ['name' => 'Cedar Gardens', 'slug' => 'cedar-gardens']);
    $list = buildingsSnapshot($this->owner);

    $response = LivewireHttp::update($this, $list, [], [['archive', [$building->id]]])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Cedar Gardens was archived.');

    // AGENT.md § 3 rule 7: the row is still there, not even soft deleted.
    $row = DB::table('properties')->where('id', $building->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('archived')
        ->and($row->deleted_at)->toBeNull();

    // Gone from the active list; listed under Archived; its page opens read-only.
    buildingsPage($this->owner)->assertOk()->assertDontSee('Cedar Gardens');
    buildingsPage($this->owner, '?archived=1')->assertOk()->assertSee('Cedar Gardens')->assertSee('data-test="restore-'.$building->id.'"', false);
    buildingsPage($this->owner, '/cedar-gardens')->assertOk()->assertSee('This building is archived')
        ->assertDontSee('data-test="edit-building"', false)->assertDontSee('data-test="archive-building"', false)->assertSee('data-test="restore-building"', false);
    buildingsPage($this->owner, '/cedar-gardens/edit')->assertForbidden();

    // Restored from its own page.
    $show = buildingsSnapshot($this->owner, '/cedar-gardens');
    LivewireHttp::update($this, $show, [], [['restore']])->assertOk();

    expect($building->fresh()->status)->toBe(PropertyStatus::Active)
        ->and(AuditEntry::query()->where('log_name', 'properties')->orderBy('id')->pluck('event')->all())->toBe(['property.archived', 'property.restored']);

    // And archived again from its page.
    LivewireHttp::update($this, buildingsSnapshot($this->owner, '/cedar-gardens'), [], [['archive']])->assertOk();
    expect($building->fresh()->status)->toBe(PropertyStatus::Archived)
        ->and(DB::table('properties')->where('id', $building->id)->value('deleted_at'))->toBeNull();
});

it('answers 404 for another organization\'s building guessed by slug or ID, on every page and action', function () {
    $foreign = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
    $foreignArchived = buildingIn($this->other, ['name' => 'Foreign Archive', 'slug' => 'foreign-archive', 'status' => PropertyStatus::Archived]);
    $shared = buildingIn($this->other, ['name' => 'Their Palm Court', 'slug' => 'palm-court']);
    $own = buildingIn($this->acme, ['name' => 'Our Palm Court', 'slug' => 'palm-court']);

    // Pages, by slug.
    buildingsPage($this->owner, '/foreign-court')->assertNotFound();
    buildingsPage($this->owner, '/foreign-court/edit')->assertNotFound();
    buildingsPage($this->owner, '/foreign-archive')->assertNotFound();
    buildingsPage($this->owner, "/{$foreign->id}")->assertNotFound();
    buildingsPage($this->owner, '/palm-court')->assertOk()->assertSee('Our Palm Court')->assertDontSee('Their Palm Court');

    // List actions, by ID.
    $list = buildingsSnapshot($this->owner);
    LivewireHttp::update($this, $list, [], [['archive', [$foreign->id]]])->assertNotFound();
    LivewireHttp::update($this, $list, [], [['restore', [$foreignArchived->id]]])->assertNotFound();

    // The locked building id on the page and the form cannot be swapped.
    // Livewire answers a tampered snapshot with 419 in production (debug off);
    // CI's .env turns debug on, so pin it here.
    config(['app.debug' => false]);
    foreach (['/palm-court', '/palm-court/edit'] as $path) {
        $tampered = json_decode(buildingsSnapshot($this->owner, $path), true);
        $tampered['data']['propertyId'] = $foreign->id;
        LivewireHttp::update($this, (string) json_encode($tampered), [], [[$path === '/palm-court' ? 'archive' : 'save']])->assertStatus(419);
    }

    // A member of both organizations, inside Acme's workspace, still gets 404.
    joinAs($this->owner, $this->other);
    buildingsPage($this->owner, '/foreign-court')->assertNotFound();
    buildingsPage($this->owner, '/foreign-court', 'other-realty')->assertOk();

    $foreignNow = buildingsOf($this->other);
    expect($foreignNow->firstWhere('id', $foreign->id)->status)->toBe(PropertyStatus::Active)
        ->and($foreignNow->firstWhere('id', $foreignArchived->id)->status)->toBe(PropertyStatus::Archived)
        ->and($foreignNow->firstWhere('id', $shared->id)->name)->toBe('Their Palm Court')
        ->and($own->fresh()->status)->toBe(PropertyStatus::Active);
    buildingsPage($this->owner)->assertOk()->assertSee('Our Palm Court')->assertDontSee('Foreign Court');
});

it('searches, filters and pages the list on the server, keeping the choices in the URL', function () {
    $landlord = ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze']);
    app(OrganizationContext::class)->run($this->acme, function () use ($landlord): void {
        Property::factory()->count(PropertyIndex::PER_PAGE + 5)->sequence(fn ($sequence) => ['name' => sprintf('Block %03d', $sequence->index), 'state' => 'Oyo'])->create();
        Property::factory()->create(['name' => 'Harmony Estate', 'property_type' => PropertyType::Estate, 'state' => 'FCT', 'landmark' => 'Near Jabi Lake Mall']);
        Property::factory()->ownedBy($landlord)->create(['name' => 'Royal Residence', 'property_type' => PropertyType::Duplex, 'state' => 'Lagos', 'lga' => 'Ikeja']);
    });

    // Paging: 25 a page, the rest on page 2.
    buildingsPage($this->owner)->assertOk()->assertSee('Block 000')->assertSee('Block 024')->assertDontSee('Block 025');
    buildingsPage($this->owner, '?page=2')->assertOk()->assertSee('Block 025')->assertDontSee('Block 024');

    // Search over name, address, landmark and LGA; filters by type, state and owner.
    buildingsPage($this->owner, '?q=jabi')->assertOk()->assertSee('Harmony Estate')->assertDontSee('Block 000');
    buildingsPage($this->owner, '?q=ikeja')->assertOk()->assertSee('Royal Residence')->assertDontSee('Harmony Estate');
    buildingsPage($this->owner, '?type=estate')->assertOk()->assertSee('Harmony Estate')->assertDontSee('Royal Residence');
    buildingsPage($this->owner, '?state=Lagos')->assertOk()->assertSee('Royal Residence')->assertDontSee('Harmony Estate');
    buildingsPage($this->owner, "?owner={$landlord->id}")->assertOk()->assertSee('Royal Residence')->assertDontSee('Block 000');
    buildingsPage($this->owner, '?q=nothing-like-this')->assertOk()->assertSee('No building matches');

    // Live: typing resets to page 1.
    $list = buildingsSnapshot($this->owner, '?page=2');
    $response = LivewireHttp::update($this, $list, ['search' => 'royal'])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Royal Residence')->not->toContain('Block 025');

    // Clearing the filters brings everything back.
    $cleared = LivewireHttp::update($this, LivewireHttp::next($response), [], [['clearFilters']])->assertOk();
    expect(LivewireHttp::html($cleared))->toContain('Block 000');
});

it('loads the list without a query per building (no N+1)', function () {
    // Only the queries on the list's own tables: sessions, caches and
    // permission lookups vary with what earlier requests warmed.
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        buildingsPage($this->owner)->assertOk();
        DB::disableQueryLog();

        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/`(properties|owner_profiles|media)`/', $query['query']) === 1));
    };

    app(OrganizationContext::class)->run($this->acme, fn () => Property::factory()->count(2)->ownedBy(ownerIn($this->acme))->create());
    $few = $count();

    app(OrganizationContext::class)->run($this->acme, function (): void {
        foreach (range(1, 10) as $i) {
            Property::factory()->ownedBy(OwnerProfile::factory()->create())->create();
        }
    });

    expect($count())->toBe($few);
});

it('shows an owner\'s buildings on the owner page, and will not archive an owner who still owns active ones', function () {
    $landlord = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $active = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'owner_profile_id' => $landlord->id]);
    buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard', 'owner_profile_id' => $landlord->id, 'status' => PropertyStatus::Archived]);

    ownersPage($this->owner, "/{$landlord->id}")->assertOk()
        ->assertSee('data-test="owner-buildings"', false)
        ->assertSee('Palm Court')->assertSee('Old Yard')
        ->assertSee('/o/acme-homes/buildings/palm-court', false)
        ->assertSee("/o/acme-homes/buildings?owner={$landlord->id}", false);

    $list = ownersSnapshot($this->owner);
    $refused = LivewireHttp::update($this, $list, [], [['archive', [$landlord->id]]])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('Tunde Bakare still owns 1 active building.')
        ->and($landlord->fresh()->trashed())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'owner.archived')->exists())->toBeFalse();

    // Once the building is archived (the archived one does not count), the owner can be archived.
    inOrganization($this->acme, fn () => $active->forceFill(['status' => PropertyStatus::Archived])->save());
    LivewireHttp::update($this, LivewireHttp::next($refused), [], [['archive', [$landlord->id]]])->assertOk();
    expect($landlord->fresh()->trashed())->toBeTrue();
});
