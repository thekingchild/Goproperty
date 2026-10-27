<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Exports\ListExports;
use App\Exports\Livewire\WithListExports;
use App\Members\MemberInvitations;
use App\Models\Organization;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Support\Money;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\WithPagination;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Models\ImportBatch;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Livewire\PropertyShow;
use Modules\Properties\Livewire\UnitIndex;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Transfers\BillingTransferService;
use Tests\Fixtures\Livewire\UnexportedListProbe;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Modules/Tenants/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Modules/Leasing/tests/Feature/helpers.php';

/*
 * P02-07 AC "every list page exports" (IMPLEMENTATION_PLAN.md § 3;
 * docs/plans/P02-P03-subtasks.md: "a test walks every registered workspace
 * list route and asserts it has an export"). The pages come from the
 * sidebar registry (App\Navigation\WorkspaceNavigation, where every module
 * adds its pages) plus the lists inside pages reached from them, so a new
 * list page without an export fails here; every paginated Livewire
 * component must export too.
 */

/** Sidebar pages that hold no list, with the reason (anything else must export). */
const NOT_A_LIST = [
    'home' => 'the workspace dashboard: counts and links',
    'compliance' => 'one Transaction Tier application and its documents (a form)',
    'settings' => 'settings forms: profile, payment collection, branding',
];

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner']), $this->other);

    // A row in every list of the workspace.
    $owner = ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze']);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'owner_profile_id' => $owner->id]);
    $flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1', 'base_rent_kobo' => Money::naira(1_500_000), 'status' => UnitStatus::Vacant]);
    assignIn($this->acme, $this->agent, $this->palm);
    $kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']);
    draftLease($this->acme, $this->owner, $flat, $kemi);
    inOrganization($this->acme, function (): void {
        ImportBatch::query()->create(['kind' => 'workbook', 'file_type' => 'xlsx', 'file_name' => 'portfolio.xlsx', 'status' => ImportStatus::Uploaded, 'created_by_user_id' => $this->owner->id]);
        app(MemberInvitations::class)->invite($this->owner, 'new.agent@acme.ng', [OrganizationRole::Agent]);
        app(BillingTransferService::class)->initiate($this->owner, $this->other);
    });
    inOrganization($this->other, fn () => app(BillingTransferService::class)->initiate($otherOwner, $this->acme));
});

afterEach(fn () => exportsFreshRequest());

/**
 * The workspace's list pages: every sidebar page except NOT_A_LIST, and the
 * pages inside them that hold lists of their own (a building's page: its
 * agents and units). Fails for a sidebar page whose component does not use
 * WithListExports.
 *
 * @return array<string, string> path after /o/{slug} => component class
 */
function workspaceListPages(User $user, Organization $organization, string $buildingSlug): array
{
    $missing = listPagesWithoutExport($user, $organization);
    expect($missing)->toBe([], 'Every list page exports (AGENT.md § 5): use App\Exports\Livewire\WithListExports in '.implode(', ', $missing).'.');

    $pages = [];

    foreach (app(WorkspaceNavigation::class)->all() as $item) {
        if (! array_key_exists($item->key, NOT_A_LIST)) {
            $route = Route::getRoutes()->getByName($item->routeFor($user, $organization));
            $pages[parse_url(route($item->routeFor($user, $organization), $organization), PHP_URL_PATH)] = $route?->action['livewire_component'];
        }
    }

    // Lists inside pages reached from the sidebar.
    $pages["/o/{$organization->slug}/buildings/{$buildingSlug}"] = PropertyShow::class;

    return array_map(static fn (mixed $component): string => (string) $component, $pages);
}

/**
 * Sidebar pages that are lists but have no export: their component is not
 * a Livewire component using WithListExports.
 *
 * @return array<string, string> nav key => what the page runs
 */
function listPagesWithoutExport(User $user, Organization $organization): array
{
    $missing = [];

    foreach (app(WorkspaceNavigation::class)->all() as $item) {
        if (array_key_exists($item->key, NOT_A_LIST)) {
            continue;
        }

        $route = Route::getRoutes()->getByName($item->routeFor($user, $organization));
        $component = $route?->action['livewire_component'] ?? null;

        if (! is_string($component) || ! in_array(WithListExports::class, class_uses_recursive($component), true)) {
            $missing[$item->key] = is_string($component) ? $component : (string) ($route?->getActionName() ?? 'no route');
        }
    }

    return $missing;
}

/** @return list<class-string> Livewire components of Core and the modules that paginate a list */
function paginatedComponents(): array
{
    $files = [...File::allFiles(app_path('Livewire'))];

    foreach (File::directories(base_path('Modules')) as $module) {
        if (is_dir($module.'/app/Livewire')) {
            $files = [...$files, ...File::allFiles($module.'/app/Livewire')];
        }
    }

    $classes = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) === 1 && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class) === 1) {
            $name = $namespace[1].'\\'.$class[1];

            if (class_exists($name) && in_array(WithPagination::class, class_uses_recursive($name), true)) {
                $classes[] = $name;
            }
        }
    }

    sort($classes);

    return $classes;
}

it('gives every workspace list page a working export, in Excel and CSV', function () {
    $registry = app(ListExports::class);
    $offered = [];

    foreach (workspaceListPages($this->owner, $this->acme, 'palm-court') as $path => $component) {
        $relative = substr($path, strlen('/o/acme-homes'));
        $html = (string) exportPage($this->owner, $relative)->assertOk()->getContent();
        preg_match_all('/data-test="export-([a-z0-9_]+)-csv"/', $html, $buttons);
        $lists = array_values(array_unique($buttons[1]));

        expect($lists)->not->toBeEmpty("{$path} ({$component}) shows no Export button.");

        foreach ($lists as $list) {
            $export = $registry->find($list);
            expect($export)->not->toBeNull("{$path} offers \"{$list}\", which is not registered in ListExports.");
            $headers = array_map(static fn ($column): string => $column->header, $export->columns($this->owner));

            foreach (['xlsx', 'csv'] as $format) {
                $rows = exportRows($this->owner, $relative, $list, $format);

                expect($rows[0])->toBe($headers, "{$list} ({$format}) from {$path}")
                    ->and(count($rows))->toBeGreaterThan(1, "{$list} ({$format}) from {$path} has no rows.");
            }

            $offered[] = $list;
        }
    }

    // Every registered list is offered on a page, and only registered lists are.
    $offered = array_values(array_unique($offered));
    sort($offered);
    expect($offered)->toBe(array_keys($registry->keys()))
        ->toBe(['billing_transfers_made', 'billing_transfers_received', 'building_agents', 'buildings', 'imports', 'leases', 'member_invitations', 'members', 'owners', 'tenants', 'units']);
});

it('makes every Livewire component that pages through a list use the export', function () {
    $components = paginatedComponents();

    expect($components)->toContain(UnitIndex::class);

    foreach ($components as $component) {
        expect(in_array(WithListExports::class, class_uses_recursive($component), true))->toBeTrue("{$component} paginates a list but has no export (AGENT.md § 5).");
    }
});

it('fails the check for a new list page without an export', function () {
    Route::middleware(['web', 'auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
        Route::livewire('probe-list', UnexportedListProbe::class)->name('probe-list');
    });
    Route::getRoutes()->refreshNameLookups();

    expect(listPagesWithoutExport($this->owner, $this->acme))->toBe([]);

    app(WorkspaceNavigation::class)->add(new WorkspaceNavItem(
        key: 'probe-list',
        label: 'Probe list',
        icon: 'list-bullet',
        route: 'workspace.probe-list',
        active: ['workspace.probe-list'],
        visible: static fn (): bool => true,
    ));

    expect(listPagesWithoutExport($this->owner, $this->acme))->toBe(['probe-list' => UnexportedListProbe::class]);
});

it('shows the Export button only to members who may export the list', function () {
    // An agent sees buildings, units and owners, but not imports, members or billing transfers.
    $html = (string) exportPage($this->agent, '/units')->assertOk()->getContent();
    expect($html)->toContain('data-test="export-units-csv"');
    exportPage($this->agent, '/imports')->assertForbidden();
    exportPage($this->agent, '/settings/members')->assertForbidden();

    // Calling export() for a list the page does not show is refused.
    pressExport($this->owner, '/units', 'owners')->assertNotFound();
    pressExport($this->owner, '/units', 'units', 'pdf')->assertNotFound();

    expect(inOrganization($this->acme, fn () => Unit::query()->count()))->toBe(1);
});
