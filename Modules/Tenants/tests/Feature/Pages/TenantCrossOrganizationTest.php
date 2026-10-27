<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-01a sub-AC "cross-org probes": another organization's tenant, guessed
 * by ID, answers 404 on every tenant page and action, and is never listed,
 * searched, exported or changed. (CrossTenantAccessTest probes the model
 * itself, P02-06b.)
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->foreign = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348031234567', 'email' => 'foreign@other.ng']);
    $this->archivedForeign = tenantIn($this->other, ['first_name' => 'Archived', 'last_name' => 'Foreign', 'phone' => '+2348090000009']);
    inOrganization($this->other, fn () => $this->archivedForeign->delete());
    $this->own = tenantIn($this->acme, ['first_name' => 'Own', 'last_name' => 'Tenant']);
});

afterEach(fn () => tenantPagesFreshRequest());

it('answers 404 for another organization\'s tenant guessed by ID, on every page', function () {
    foreach ([$this->foreign, $this->archivedForeign] as $tenant) {
        tenantsPage($this->owner, "/{$tenant->id}")->assertNotFound();
        tenantsPage($this->owner, "/{$tenant->id}/edit")->assertNotFound();
    }

    // Ids past the last tenant, and malformed ones.
    tenantsPage($this->owner, '/999999')->assertNotFound();
    tenantsPage($this->owner, '/abc')->assertNotFound();
    tenantsPage($this->owner, "/{$this->own->id}")->assertOk();

    // Never listed, searched or filtered into view.
    foreach (['', '?archived=1', '?q=Foreign', '?q=0803+123+4567', '?q=other.ng', '?portal=no&archived=1'] as $query) {
        tenantsPage($this->owner, $query)->assertOk()->assertDontSee('Foreign Tenant')->assertDontSee('Archived Foreign');
    }
});

it('answers 404 for another organization\'s tenant on every action sent straight to the server', function () {
    $list = tenantsSnapshot($this->owner);
    LivewireHttp::update($this, $list, [], [['archive', [$this->foreign->id]]])->assertNotFound();
    LivewireHttp::update($this, tenantsSnapshot($this->owner, '?archived=1'), [], [['restore', [$this->archivedForeign->id]]])->assertNotFound();

    // Livewire answers a tampered snapshot with 419 in production (debug off);
    // CI's .env turns debug on, so pin it here.
    config(['app.debug' => false]);

    // The locked ids swapped for the foreign tenant's: record page, edit form, restore instead.
    $tamper = function (string $snapshot, string $property, int $id): string {
        $data = json_decode($snapshot, true);
        $data['data'][$property] = $id;

        return (string) json_encode($data);
    };

    $show = tenantsSnapshot($this->owner, "/{$this->own->id}");
    LivewireHttp::update($this, $tamper($show, 'tenantId', $this->foreign->id), [], [['archive']])->assertStatus(419);

    $edit = tenantsSnapshot($this->owner, "/{$this->own->id}/edit");
    LivewireHttp::update($this, $tamper($edit, 'tenantId', $this->foreign->id), ['firstName' => 'Hijacked'], [['save']])->assertStatus(419);

    $create = tenantsSnapshot($this->owner, '/create');
    LivewireHttp::update($this, $tamper($create, 'archivedMatchId', $this->archivedForeign->id), [], [['restoreArchivedMatch']])->assertStatus(419);

    // The foreign tenants are untouched.
    $foreign = tenantsOf($this->other);
    expect($foreign->firstWhere('id', $this->foreign->id)->first_name)->toBe('Foreign')
        ->and($foreign->firstWhere('id', $this->foreign->id)->trashed())->toBeFalse()
        ->and($foreign->firstWhere('id', $this->archivedForeign->id)->trashed())->toBeTrue();
});

it('keeps phone numbers per organization: another organization\'s tenant never blocks or is restored', function () {
    // The foreign tenant's number, and the archived foreign tenant's: both free here.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm(['phone' => '0803 123 4567']), [['save']])->assertOk();
    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm(['phone' => '0809 000 0009', 'firstName' => 'Bisi']), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toBe([])
        ->and(LivewireHttp::html($response))->not->toContain('data-test="restore-instead"')
        ->and(tenantsOf($this->acme)->pluck('phone')->all())->toContain('+2348031234567', '+2348090000009')
        ->and(tenantsOf($this->other)->firstWhere('id', $this->archivedForeign->id)->trashed())->toBeTrue();
});

it('shows a member of both organizations only the organization they are working in', function () {
    joinAs($this->owner, $this->other);

    tenantsPage($this->owner, "/{$this->foreign->id}")->assertNotFound();
    tenantsPage($this->owner, "/{$this->foreign->id}", 'other-realty')->assertOk()->assertSee('Foreign Tenant');
    tenantsPage($this->owner, "/{$this->own->id}", 'other-realty')->assertNotFound();
    tenantsPage($this->owner)->assertOk()->assertSee('Own Tenant')->assertDontSee('Foreign Tenant');
    tenantsPage($this->owner, '', 'other-realty')->assertOk()->assertSee('Foreign Tenant')->assertDontSee('Own Tenant');

    // Acting from Acme's list on the tenant of Other Realty: 404.
    LivewireHttp::update($this, tenantsSnapshot($this->owner), [], [['archive', [$this->foreign->id]]])->assertNotFound();
    expect(tenantsOf($this->other)->firstWhere('id', $this->foreign->id)->trashed())->toBeFalse();
});

it('never lets a stranger or another organization\'s member open the tenant pages', function () {
    $outsider = joinAs(User::factory()->create(), $this->other);
    $stranger = User::factory()->create();

    foreach ([$outsider, $stranger] as $user) {
        tenantsPage($user)->assertNotFound();
        tenantsPage($user, '/create')->assertNotFound();
        tenantsPage($user, "/{$this->own->id}")->assertNotFound();
        tenantsPage($user, "/{$this->own->id}/edit")->assertNotFound();
    }

    tenantPagesFreshRequest();
    $this->get('/o/acme-homes/tenants')->assertRedirect();
});
