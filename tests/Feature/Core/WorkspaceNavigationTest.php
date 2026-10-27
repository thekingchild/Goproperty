<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Tenancy\OrganizationContext;

/*
 * P02-01a: the workspace sidebar comes from App\Navigation\WorkspaceNavigation.
 * Core registers its pages, modules add theirs from their providers, and
 * each item shows only to members its permission check lets in (the pages
 * check again).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** @return list<string> the sidebar's workspace items for the user, in order */
function sidebarItemsOf(User $user): array
{
    $html = (string) test()->actingAs($user)->get('/o/acme-homes')->assertOk()->getContent();
    preg_match_all('/data-nav="([a-z-]+)"/', $html, $matches);

    return $matches[1];
}

it('shows each role the workspace pages it may open, Core and module pages alike', function (OrganizationRole $role, array $items) {
    expect(sidebarItemsOf(joinAs(User::factory()->create(), $this->acme, $role)))->toBe($items);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['home', 'buildings', 'units', 'owners', 'tenants', 'imports', 'compliance', 'members', 'billing-transfers', 'settings']],
    'org-admin' => [OrganizationRole::Admin, ['home', 'buildings', 'units', 'owners', 'tenants', 'imports', 'members', 'settings']],
    'agent' => [OrganizationRole::Agent, ['home', 'buildings', 'units', 'owners', 'tenants']],
]);

it('links each item to its page in the current organization', function () {
    $owner = joinAs(User::factory()->create(), $this->acme);

    $this->actingAs($owner)->get('/o/acme-homes/owners')->assertOk()
        ->assertSee('href="http://localhost/o/acme-homes/buildings"', false)
        ->assertSee('href="http://localhost/o/acme-homes/owners"', false)
        ->assertSee('href="http://localhost/o/acme-homes/compliance/tier"', false)
        ->assertSee('href="http://localhost/o/acme-homes/settings/members"', false)
        ->assertSee('href="http://localhost/o/acme-homes/settings/profile"', false);

    $links = app(OrganizationContext::class)->run($this->acme, fn () => app(WorkspaceNavigation::class)->for($owner, $this->acme));
    expect(collect($links)->firstWhere('key', 'owners'))->toMatchArray([
        'label' => 'Owners',
        'url' => 'http://localhost/o/acme-homes/owners',
        'active' => ['workspace.owners.*'],
    ]);
});

it('skips items whose page does not exist and refuses a key used twice', function () {
    $navigation = new WorkspaceNavigation;
    $item = fn (string $key, string $route, int $order) => new WorkspaceNavItem($key, ucfirst($key), 'home', $route, [$route], static fn (): bool => true, $order);

    $navigation->add($item('later', 'workspace.home', 20));
    $navigation->add($item('first', 'workspace.home', 10));
    $navigation->add($item('missing', 'workspace.not-built-yet', 5));

    expect(array_column($navigation->for(joinAs(User::factory()->create(), $this->acme), $this->acme), 'key'))->toBe(['first', 'later'])
        ->and(fn () => $navigation->add($item('first', 'workspace.home', 1)))->toThrow(LogicException::class, 'already has an item "first"');
});
