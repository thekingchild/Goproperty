<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;

/*
 * P00-09a: the app shell (UX_DESIGN.md § 2, § 6): workspace switcher
 * placeholder, surface navigation, bottom navigation on phones, page
 * templates with empty states, and no starter-kit branding.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->organizations()->attach(Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']));
});

it('offers every workspace and portal in the switcher', function () {
    $this->actingAs($this->user)->get('/o/acme-homes')
        ->assertOk()
        ->assertSee('data-test="org-switcher"', false)
        ->assertSee('Acme Homes')
        ->assertSee(route('workspace.home', 'acme-homes'), false)
        ->assertSee(route('portal.home'), false)
        ->assertSee(route('owner.home'), false);
});

it('asks the user to choose a workspace outside one', function () {
    $this->actingAs($this->user)->get('/portal')->assertSee('Choose a workspace');
});

it('has a bottom navigation for phones', function () {
    $this->actingAs($this->user)->get('/portal')
        ->assertSee('data-test="bottom-nav"', false)
        ->assertSee('aria-current="page"', false);
});

it('shows an empty state with a next step on each surface', function (string $path, string $heading) {
    $this->actingAs($this->user)->get($path)->assertOk()->assertSee($heading);
})->with([
    ['/dashboard', 'Pick where to start'],
    ['/o/acme-homes', 'Nothing to do yet'],
    ['/portal', 'No rentals yet'],
    ['/owner', 'No properties yet'],
]);

it('carries no starter-kit branding', function () {
    $this->actingAs($this->user)->get('/portal')
        ->assertDontSee('livewire-starter-kit')
        ->assertDontSee('Laravel')
        ->assertSee('GoProperty');
});
