<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Livewire\OwnerProfileIndex;
use Modules\Properties\Models\OwnerProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P02-01a: the Owners pages (/o/{slug}/owners): add, open and save, archive
 * and restore an owner; the ID number encrypted at rest and never sent back
 * whole; another organization's owners unreachable by ID.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('adds a person owner from the page, normalising the details, and audits it', function () {
    $snapshot = ownersSnapshot($this->owner, '/create');

    $response = LivewireHttp::update($this, $snapshot, ownerForm(), [['save']])->assertOk();

    $owner = ownersOf($this->acme)->sole();
    expect(LivewireHttp::redirect($response))->toBe("http://localhost/o/acme-homes/owners/{$owner->id}")
        ->and($owner->type)->toBe(OwnerType::Person)
        ->and($owner->display_name)->toBe('Adaeze Okafor')
        ->and($owner->company_name)->toBeNull()
        ->and($owner->email)->toBe('adaeze@example.ng')
        ->and($owner->phone)->toBe('+2348031234567')
        ->and($owner->state)->toBe('Lagos')
        ->and($owner->id_type)->toBe(OwnerIdType::Nin)
        ->and($owner->id_value)->toBe('12345678901')
        ->and($owner->organization_id)->toBe($this->acme->id);

    $entry = AuditEntry::query()->where('log_name', 'owners')->sole();
    expect($entry->event)->toBe('owner.created')
        ->and($entry->subject_id)->toBe($owner->id)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and(json_encode($entry->properties))->not->toContain('12345678901');

    ownersPage($this->owner)->assertOk()->assertSee('Adaeze Okafor')->assertSee('+2348031234567');
});

it('adds a company owner with its CAC number, keeping no person names', function () {
    $snapshot = ownersSnapshot($this->owner, '/create');

    LivewireHttp::update($this, $snapshot, ownerForm(['type' => 'company', 'companyName' => 'Ikoyi Holdings Ltd', 'idType' => 'cac', 'idValue' => 'rc  1234567']), [['save']])->assertOk();

    $owner = ownersOf($this->acme)->sole();
    expect($owner->type)->toBe(OwnerType::Company)
        ->and($owner->display_name)->toBe('Ikoyi Holdings Ltd')
        ->and($owner->first_name)->toBeNull()
        ->and($owner->last_name)->toBeNull()
        ->and($owner->id_value)->toBe('RC 1234567');
});

it('refuses invalid details on the server, saving nothing', function (array $changes, string $field) {
    $snapshot = ownersSnapshot($this->owner, '/create');

    $response = LivewireHttp::update($this, $snapshot, ownerForm($changes), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(ownersOf($this->acme))->toHaveCount(0);
})->with([
    'no first name' => [['firstName' => ''], 'firstName'],
    'no company name' => [['type' => 'company', 'companyName' => ''], 'companyName'],
    'unknown type' => [['type' => 'trust'], 'type'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'bad phone' => [['phone' => '12345'], 'phone'],
    'unknown state' => [['state' => 'Atlantis'], 'state'],
    'short NIN' => [['idValue' => '1234567'], 'idValue'],
    'NIN with letters' => [['idValue' => '1234567890A'], 'idValue'],
    'missing NIN' => [['idValue' => ''], 'idValue'],
    'bad CAC number' => [['idType' => 'cac', 'idValue' => 'ABC'], 'idValue'],
    'unknown ID type' => [['idType' => 'passport'], 'idType'],
    'markup in a name' => [['lastName' => '<b>Okafor</b>'], 'lastName'],
    'notes too long' => [['notes' => str_repeat('a', 2001)], 'notes'],
]);

it('stores the ID number encrypted at rest and never shows it whole', function () {
    $owner = ownerIn($this->acme, ['id_type' => OwnerIdType::Nin, 'id_value' => '98765432109']);

    $raw = DB::table('owner_profiles')->where('id', $owner->id)->value('id_value');

    expect($raw)->toBeString()
        ->not->toBe('98765432109')
        ->not->toContain('98765432109')
        ->and(Crypt::decryptString($raw))->toBe('98765432109')
        ->and($owner->fresh()->id_value)->toBe('98765432109')
        ->and($owner->fresh()->toArray())->not->toHaveKey('id_value');

    // The page and its Livewire snapshot carry only the masked number.
    $page = ownersPage($this->owner, "/{$owner->id}")->assertOk()->assertSee('NIN ···2109')->assertDontSee('98765432109');
    expect(LivewireHttp::snapshot($page))->not->toContain('98765432109');
});

it('keeps the ID number on file when the field is left empty, and replaces or removes it on purpose', function () {
    $owner = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'id_type' => OwnerIdType::Nin, 'id_value' => '98765432109']);
    $snapshot = ownersSnapshot($this->owner, "/{$owner->id}");

    $response = LivewireHttp::update($this, $snapshot, ['phone' => '0812 000 1111'], [['save']])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Saved.')
        ->and($owner->fresh()->id_value)->toBe('98765432109')
        ->and($owner->fresh()->phone)->toBe('+2348120001111');

    $entry = AuditEntry::query()->where('log_name', 'owners')->where('event', 'owner.updated')->sole();
    expect($entry->properties->get('changed'))->toBe(['phone']);

    // A new number replaces it; the audit names the field, never the number.
    $response = LivewireHttp::update($this, LivewireHttp::next($response), ['idValue' => '11122233344'], [['save']])->assertOk();
    expect($owner->fresh()->id_value)->toBe('11122233344')
        ->and(AuditEntry::query()->where('event', 'owner.updated')->latest('id')->first()->properties->get('changed'))->toBe(['id_value'])
        ->and(json_encode(AuditEntry::query()->where('log_name', 'owners')->pluck('properties')))->not->toContain('11122233344');

    // Switching to a CAC number needs the number; "None" removes it.
    $refused = LivewireHttp::update($this, LivewireHttp::next($response), ['idType' => 'cac'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('idValue');

    LivewireHttp::update($this, LivewireHttp::next($response), ['idType' => 'none'], [['save']])->assertOk();
    expect($owner->fresh()->id_type)->toBe(OwnerIdType::None)
        ->and($owner->fresh()->id_value)->toBeNull();
});

it('answers 404 for another organization\'s owner guessed by ID, on every page and action', function () {
    $foreign = ownerIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Owner']);
    $archivedForeign = ownerIn($this->other, ['first_name' => 'Archived', 'last_name' => 'Foreign']);
    app(OrganizationContext::class)->run($this->other, fn () => $archivedForeign->delete());
    $own = ownerIn($this->acme, ['first_name' => 'Own', 'last_name' => 'Owner']);

    // Opening it by URL.
    ownersPage($this->owner, "/{$foreign->id}")->assertNotFound();
    ownersPage($this->owner, "/{$own->id}")->assertOk();

    // Archiving or restoring it from the list.
    $list = ownersSnapshot($this->owner);
    LivewireHttp::update($this, $list, [], [['archive', [$foreign->id]]])->assertNotFound();
    LivewireHttp::update($this, $list, [], [['restore', [$archivedForeign->id]]])->assertNotFound();

    // Saving the own owner's form with the foreign id swapped in: the id is locked.
    $form = ownersSnapshot($this->owner, "/{$own->id}");
    $tampered = json_decode($form, true);
    $tampered['data']['ownerId'] = $foreign->id;
    LivewireHttp::update($this, (string) json_encode($tampered), ['firstName' => 'Hijacked'], [['save']])->assertStatus(419);

    // A member of both organizations, inside Acme's workspace, still gets 404.
    joinAs($this->owner, $this->other);
    ownersPage($this->owner, "/{$foreign->id}")->assertNotFound();
    ownersPage($this->owner, "/{$foreign->id}", 'other-realty')->assertOk();

    $foreignNow = ownersOf($this->other);
    expect($foreignNow->firstWhere('id', $foreign->id)->first_name)->toBe('Foreign')
        ->and($foreignNow->firstWhere('id', $foreign->id)->trashed())->toBeFalse()
        ->and($foreignNow->firstWhere('id', $archivedForeign->id)->trashed())->toBeTrue();
    ownersPage($this->owner)->assertOk()->assertSee('Own Owner')->assertDontSee('Foreign Owner');
});

it('archives an owner as a soft delete, and restores it, both audited', function () {
    $owner = ownerIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $list = ownersSnapshot($this->owner);

    $response = LivewireHttp::update($this, $list, [], [['archive', [$owner->id]]])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Tunde Bakare was archived.');

    $row = DB::table('owner_profiles')->where('id', $owner->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull();

    // Gone from the list and its page; listed under Archived.
    ownersPage($this->owner)->assertOk()->assertDontSee('Tunde Bakare');
    ownersPage($this->owner, "/{$owner->id}")->assertNotFound();
    ownersPage($this->owner, '?archived=1')->assertOk()->assertSee('Tunde Bakare')->assertSee('data-test="restore-'.$owner->id.'"', false);

    $archived = ownersSnapshot($this->owner, '?archived=1');
    LivewireHttp::update($this, $archived, [], [['restore', [$owner->id]]])->assertOk();

    expect($owner->fresh()->trashed())->toBeFalse()
        ->and(AuditEntry::query()->where('log_name', 'owners')->orderBy('id')->pluck('event')->all())->toBe(['owner.archived', 'owner.restored']);
    ownersPage($this->owner)->assertOk()->assertSee('Tunde Bakare');
});

it('searches by name, email and phone, and pages the list on the server', function () {
    app(OrganizationContext::class)->run($this->acme, function (): void {
        OwnerProfile::factory()->count(OwnerProfileIndex::PER_PAGE + 5)->sequence(fn ($sequence) => ['first_name' => 'Owner', 'last_name' => sprintf('N%03d', $sequence->index)])->create();
        OwnerProfile::factory()->create(['first_name' => 'Chiamaka', 'last_name' => 'Eze', 'email' => 'chiamaka@eze.ng', 'phone' => '+2348091112222']);
        OwnerProfile::factory()->company()->create(['company_name' => 'Lekki Gardens Ltd']);
    });

    ownersPage($this->owner)->assertOk()->assertSee('Chiamaka Eze')->assertSee('Lekki Gardens Ltd')->assertSee('Owner N000')->assertDontSee('Owner N029');
    ownersPage($this->owner, '?page=2')->assertOk()->assertSee('Owner N029')->assertDontSee('Chiamaka Eze');

    foreach (['chiam', 'eze.ng', '0809 111', 'Lekki'] as $term) {
        $page = ownersPage($this->owner, '?q='.urlencode($term))->assertOk();
        $term === 'Lekki'
            ? $page->assertSee('Lekki Gardens Ltd')->assertDontSee('Chiamaka Eze')
            : $page->assertSee('Chiamaka Eze')->assertDontSee('Lekki Gardens Ltd');
        $page->assertDontSee('Owner N000');
    }

    // The search box updates the list through Livewire (debounced on the page).
    $snapshot = ownersSnapshot($this->owner);
    $html = LivewireHttp::html(LivewireHttp::update($this, $snapshot, ['search' => 'Lekki'])->assertOk());
    expect($html)->toContain('Lekki Gardens Ltd')->not->toContain('Chiamaka Eze');

    ownersPage($this->owner, '?q='.urlencode('100%_'))->assertOk()->assertSee('No owner matches');
});

it('shows a clear next step when there are no owners yet', function () {
    ownersPage($this->owner)->assertOk()->assertSee('No owners yet')->assertSee('/o/acme-homes/owners/create');
});
