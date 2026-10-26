<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Models\ScopedRecord;
use Tests\Support\TenancyFixture;

/*
 * P00-07: /o/{organization} routes set the context from the slug after a
 * membership check, before route model binding (ARCHITECTURE.md § 4).
 */

beforeEach(function () {
    TenancyFixture::create();

    Route::middleware(['web', 'auth', 'organization'])->prefix('o/{organization}')->group(function () {
        Route::get('probe', fn (Organization $organization) => response()->json([
            'organization' => $organization->slug,
            'context' => app(OrganizationContext::class)->get()->slug,
            'link' => route('probe.records', ['record' => 7], false),
        ]))->name('probe');

        Route::get('records/{record}', fn (Organization $organization, ScopedRecord $record) => $record->name)->name('probe.records');
    });

    Route::getRoutes()->refreshNameLookups();

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'previous_slugs' => ['acme-old']]);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
    $this->user = User::factory()->create();
    $this->user->organizations()->attach($this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
    TenancyFixture::drop();
});

it('sets the context for a member', function () {
    $this->actingAs($this->user)
        ->getJson('/o/acme-homes/probe')
        ->assertOk()
        ->assertExactJson(['organization' => 'acme-homes', 'context' => 'acme-homes', 'link' => '/o/acme-homes/records/7']);
});

it('answers 404 to non-members and unknown slugs alike', function (string $slug) {
    $this->actingAs($this->user)->get("/o/{$slug}/probe")->assertNotFound();
})->with(['other-realty', 'no-such-org', 'other-old']);

it('sends guests to sign in', function () {
    $this->get('/o/acme-homes/probe')->assertRedirect('/login');
});

it('redirects an old slug to the current one', function () {
    $this->actingAs($this->user)
        ->get('/o/acme-old/probe?tab=bills')
        ->assertStatus(301)
        ->assertRedirect('http://localhost/o/acme-homes/probe?tab=bills');
});

it('does not reveal a renamed organization to non-members', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get('/o/acme-old/probe')->assertNotFound();
});

it('scopes models bound from the URL', function () {
    $own = app(OrganizationContext::class)->run($this->acme, fn () => ScopedRecord::query()->create(['name' => 'Acme flat']));
    $foreign = app(OrganizationContext::class)->run($this->other, fn () => ScopedRecord::query()->create(['name' => 'Other flat']));

    $this->actingAs($this->user)->get("/o/acme-homes/records/{$own->id}")->assertOk()->assertSee('Acme flat');
    $this->actingAs($this->user)->get("/o/acme-homes/records/{$foreign->id}")->assertNotFound();
});
