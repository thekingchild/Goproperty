<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the unit tests (P02-02). Required by the files in this
 * folder (with ../Buildings/helpers.php for buildings and owners).
 */

require_once __DIR__.'/../Buildings/helpers.php';

/** A unit made inside the organization, in the building, as a request there would. */
function unitIn(Organization $organization, Property $property, array $attributes = []): Unit
{
    return app(OrganizationContext::class)->run($organization, fn (): Unit => Unit::factory()->in($property)->create($attributes));
}

/** Every unit of the organization, archived ones too, oldest first. */
function unitsOf(Organization $organization): Collection
{
    return app(OrganizationContext::class)->run($organization, fn () => Unit::query()->withTrashed()->orderBy('id')->get());
}

/** A page of a building's units area, as the user ('/create', '/bulk', '/{id}/edit'). */
function unitsPage(User $user, string $building, string $path, string $slug = 'acme-homes'): TestResponse
{
    return test()->actingAs($user)->get("/o/{$slug}/buildings/{$building}/units{$path}");
}

/** The Livewire snapshot of a units page the user can open. */
function unitsSnapshot(User $user, string $building, string $path, string $slug = 'acme-homes'): string
{
    return LivewireHttp::snapshot(unitsPage($user, $building, $path, $slug)->assertOk());
}

/** Form fields for one unit, with changes. */
function unitForm(array $changes = []): array
{
    return $changes + [
        'unitLabel' => 'Flat 1',
        'unitType' => 'apartment',
        'bedrooms' => '3',
        'bathrooms' => '2',
        'sizeSqm' => '120',
        'floor' => 'Ground',
        'baseRent' => '2,500,000',
        'serviceCharge' => '250,000.50',
        'status' => 'vacant',
        'description' => 'Corner flat with a balcony.',
    ];
}

/** Form fields for several units at once, with changes. */
function bulkForm(array $changes = []): array
{
    return $changes + [
        'pattern' => 'Flat {n}',
        'start' => '1',
        'count' => '10',
        'unitType' => 'apartment',
        'bedrooms' => '2',
        'bathrooms' => '2',
        'sizeSqm' => '',
        'floor' => '',
        'baseRent' => '1,800,000',
        'serviceCharge' => '200,000',
        'status' => 'vacant',
        'description' => '',
    ];
}
