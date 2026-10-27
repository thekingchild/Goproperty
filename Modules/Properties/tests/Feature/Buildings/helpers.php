<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the building tests (P02-01b). Required by the files in this
 * folder (with ../Owners/helpers.php for owners).
 */

require_once __DIR__.'/../Owners/helpers.php';

/** A building made inside the organization, as a request there would. */
function buildingIn(Organization $organization, array $attributes = []): Property
{
    return app(OrganizationContext::class)->run($organization, fn (): Property => Property::factory()->create($attributes));
}

/** Every building of the organization, soft-deleted ones too, oldest first. */
function buildingsOf(Organization $organization): Collection
{
    return app(OrganizationContext::class)->run($organization, fn () => Property::query()->withTrashed()->orderBy('id')->get());
}

/** A page of the buildings area, as the user. */
function buildingsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    return test()->actingAs($user)->get("/o/{$slug}/buildings{$path}");
}

/** The Livewire snapshot of a buildings page the user can open. */
function buildingsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    return LivewireHttp::snapshot(buildingsPage($user, $path, $slug)->assertOk());
}

/** Form fields for a building, with changes. */
function buildingForm(array $changes = []): array
{
    return $changes + [
        'name' => 'Palm Court',
        'propertyType' => 'block_of_flats',
        'ownerProfileId' => '',
        'address' => '12 Admiralty Way, Lekki Phase 1',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'landmark' => 'Opposite Mega Plaza',
        'description' => 'Six flats over two floors.',
        'amenities' => ['Borehole', 'Security'],
        'otherAmenities' => 'Rooftop terrace, borehole',
        'yearBuilt' => '2015',
    ];
}
