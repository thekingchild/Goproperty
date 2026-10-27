<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\OwnerProfile;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the owner-profile tests (P02-01a). Required by the files in
 * this folder.
 */

/** An owner profile made inside the organization, as a request there would. */
function ownerIn(Organization $organization, array $attributes = []): OwnerProfile
{
    return app(OrganizationContext::class)->run($organization, fn (): OwnerProfile => OwnerProfile::factory()->create($attributes));
}

/** Every owner profile of the organization, archived ones too, oldest first. */
function ownersOf(Organization $organization): Collection
{
    return app(OrganizationContext::class)->run($organization, fn () => OwnerProfile::query()->withTrashed()->orderBy('id')->get());
}

/** A page of the owners area, as the user. */
function ownersPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    return test()->actingAs($user)->get("/o/{$slug}/owners{$path}");
}

/** The Livewire snapshot of an owners page the user can open. */
function ownersSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    return LivewireHttp::snapshot(ownersPage($user, $path, $slug)->assertOk());
}

/** Form fields for a person owner, with changes. */
function ownerForm(array $changes = []): array
{
    return $changes + [
        'type' => 'person',
        'firstName' => 'Adaeze',
        'lastName' => 'Okafor',
        'companyName' => '',
        'email' => 'Adaeze@Example.ng',
        'phone' => '0803 123 4567',
        'address' => '12 Admiralty Way, Lekki',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'idType' => 'nin',
        'idValue' => '12345678901',
        'notes' => 'Prefers WhatsApp.',
    ];
}
