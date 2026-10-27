<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the agent assignment and unit list tests (P02-03). Required
 * by the files in this folder (with ../Units/helpers.php for buildings,
 * units and owners).
 */

require_once __DIR__.'/../Units/helpers.php';

/** The agent assigned to the building, made directly (pages use the writer). */
function assignIn(Organization $organization, User $agent, Property $property): AgentAssignment
{
    return app(OrganizationContext::class)->run($organization, fn (): AgentAssignment => AgentAssignment::factory()->of($agent, $property)->create());
}

/** Every assignment of the organization, as [user id, building id] pairs. */
function assignmentsOf(Organization $organization): Collection
{
    return app(OrganizationContext::class)->run($organization, fn () => AgentAssignment::query()->orderBy('user_id')->orderBy('property_id')->get()
        ->map(fn (AgentAssignment $row): array => [$row->user_id, $row->property_id])->values());
}

/** The unit list (/o/{slug}/units), as the user, with a query string. */
function unitListPage(User $user, string $query = '', string $slug = 'acme-homes'): TestResponse
{
    return test()->actingAs($user)->get("/o/{$slug}/units{$query}");
}

/** The Livewire snapshot of the unit list. */
function unitListSnapshot(User $user, string $query = '', string $slug = 'acme-homes'): string
{
    return LivewireHttp::snapshot(unitListPage($user, $query, $slug)->assertOk());
}

/** The HTML of a page, or of a Livewire update's re-render. */
function htmlOf(TestResponse|string $page): string
{
    if (is_string($page)) {
        return $page;
    }

    return str_contains((string) $page->headers->get('Content-Type'), 'json') ? LivewireHttp::html($page) : (string) $page->getContent();
}

/** The unit ids a rendered unit list or status board shows, in order. */
function listedUnits(TestResponse|string $page): array
{
    preg_match_all('/data-unit="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

/** The building ids a rendered buildings list shows, in order. */
function listedBuildings(TestResponse|string $page): array
{
    preg_match_all('/data-building="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
