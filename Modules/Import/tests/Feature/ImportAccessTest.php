<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Livewire\NewImport;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Tests\Fixtures\ImportFixtures;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/*
 * P02-06a: who may import (DATA_IMPORT.md § 5): only org-owners and
 * org-admins; impersonating staff see results but never commit or roll
 * back; suspended organizations are read-only; another organization's
 * batches answer 404. And the pages: upload → map → dry run → review →
 * commit → report.
 */

beforeEach(function () {
    Storage::fake('media');
    ImportFixtures::install();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    freshRequest();
    ImportFixtures::drop();
    app(OrganizationContext::class)->clear();
});

function importsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    return test()->actingAs($user)->get("/o/{$slug}/imports{$path}");
}

/** @return array<string, bool> */
function importAbilitiesOf(User $user, Organization $organization, ImportBatch $passed, ImportBatch $committed): array
{
    return inAcme($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', ImportBatch::class),
        'view' => Gate::forUser($user)->allows('view', $passed),
        'create' => Gate::forUser($user)->allows('create', ImportBatch::class),
        'update' => Gate::forUser($user)->allows('update', $passed),
        'commit' => Gate::forUser($user)->allows('commit', $passed),
        'rollback' => Gate::forUser($user)->allows('rollback', $committed),
    ]);
}

function committedImport(Organization $organization, User $by, int $rows = 3): ImportBatch
{
    $batch = dryRunImport($organization, $by, importWorkbook(['Contacts' => contactRows($rows)]));
    inAcme($organization, fn () => app(ImportBatches::class)->commit($by, $batch));

    return freshBatch($organization, $batch);
}

it('lets only org-owners and org-admins import', function (OrganizationRole $role, array $expected, int $page) {
    $passed = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    $committed = committedImport($this->acme, $this->owner);
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(importAbilitiesOf($user, $this->acme, $passed, $committed))->toBe($expected);

    importsPage($user)->assertStatus($page);
    importsPage($user, '/new')->assertStatus($page);
    importsPage($user, '/template')->assertStatus($page);
    importsPage($user, "/{$passed->id}")->assertStatus($page);
})->with([
    'org-owner' => [OrganizationRole::Owner, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'commit' => true, 'rollback' => true], 200],
    'org-admin' => [OrganizationRole::Admin, ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'commit' => true, 'rollback' => true], 200],
    'agent' => [OrganizationRole::Agent, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'commit' => false, 'rollback' => false], 403],
    // Property owners and tenants never open the workspace (ARCHITECTURE.md § 5).
    'property-owner' => [OrganizationRole::PropertyOwner, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'commit' => false, 'rollback' => false], 404],
    'tenant' => [OrganizationRole::Tenant, ['viewAny' => false, 'view' => false, 'create' => false, 'update' => false, 'commit' => false, 'rollback' => false], 404],
]);

it('refuses an agent\'s upload, dry run, commit and rollback sent straight to the services', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $passed = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    $committed = committedImport($this->acme, $this->owner);
    $batches = app(ImportBatches::class);

    foreach ([
        fn () => $batches->upload($agent, importWorkbook(['Contacts' => contactRows(1)])),
        fn () => $batches->dryRun($agent, $passed),
        fn () => $batches->commit($agent, $passed),
        fn () => $batches->rollback($agent, $committed),
    ] as $call) {
        expect(fn () => inAcme($this->acme, $call))->toThrow(AuthorizationException::class);
    }

    expect(freshBatch($this->acme, $passed)->status)->toBe(ImportStatus::DryRunPassed)
        ->and(freshBatch($this->acme, $committed)->status)->toBe(ImportStatus::Committed);
});

it('grants the import permissions to org-owners and org-admins, classed for both central guards', function () {
    $holders = fn (OrganizationPermission $permission): array => array_values(array_map(
        fn (OrganizationRole $role): string => $role->value,
        array_filter(OrganizationRole::cases(), fn (OrganizationRole $role): bool => in_array($permission, $role->permissions(), true)),
    ));

    expect($holders(OrganizationPermission::ViewImports))->toBe(['org-owner', 'org-admin'])
        ->and($holders(OrganizationPermission::ManageImports))->toBe(['org-owner', 'org-admin'])
        // Reports stay readable while suspended and to impersonating staff…
        ->and(OrganizationPermission::ViewImports->allowedWhileSuspended())->toBeTrue()
        ->and(OrganizationPermission::ViewImports->blockedWhileImpersonating())->toBeFalse()
        // …importing does not (DATA_IMPORT.md § 5).
        ->and(OrganizationPermission::ManageImports->allowedWhileSuspended())->toBeFalse()
        ->and(OrganizationPermission::ManageImports->blockedWhileImpersonating())->toBeTrue();
});

it('runs the pages from upload to report: upload, map, dry run, review, commit, report, rollback', function () {
    // Upload (Livewire file upload).
    $this->actingAs($this->owner);
    app(OrganizationContext::class)->set($this->acme);
    $rows = contactRows(3);
    $rows[2][2] = ''; // row 3 has no phone
    Livewire::test(NewImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('march.xlsx', (string) file_get_contents((string) importWorkbook(['Contacts' => $rows])->getRealPath())))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertRedirect();
    app(OrganizationContext::class)->clear();
    $batch = inAcme($this->acme, fn () => ImportBatch::query()->sole());
    $url = "/o/acme-homes/imports/{$batch->id}";

    // Map: the suggestion is on the page.
    $page = importsPage($this->owner, "/{$batch->id}")->assertOk()->assertSee('Match the columns')->assertSee('data-test="run-dry-run"', false);

    // Dry run with an error: the exact row, column and message, and the problems download.
    $response = LivewireHttp::update($this, LivewireHttp::snapshot($page), [], [['dryRun']])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Enter a phone number.')->toContain('Phone (C)')->toContain('data-test="download-problems"')->not->toContain('data-test="commit-import"');

    // A corrected file passes: review shows the preview and the subscription effect.
    $fixed = uploadImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(3)]));
    $page = importsPage($this->owner, "/{$fixed->id}")->assertOk();
    $response = LivewireHttp::update($this, LivewireHttp::snapshot($page), ['saveAs' => 'Template'], [['dryRun']])->assertOk();
    expect(LivewireHttp::html($response))->toContain('data-test="commit-import"')->toContain('How the first rows will look')->toContain('This file adds 3 units');

    // Commit (the queue runs inline in tests), then the report with rollback.
    LivewireHttp::update($this, LivewireHttp::next($response), [], [['commit']])->assertOk();
    expect(freshBatch($this->acme, $fixed)->status)->toBe(ImportStatus::Committed)
        ->and(contactsOf($this->acme))->toHaveCount(3);

    $report = importsPage($this->owner, "/{$fixed->id}")->assertOk()->assertSee('Import report')->assertSee('3 new, 0 updated, 0 restored, 0 skipped')->assertSee('data-test="rollback-import"', false);
    LivewireHttp::update($this, LivewireHttp::snapshot($report), [], [['rollback']])->assertOk();
    expect(freshBatch($this->acme, $fixed)->status)->toBe(ImportStatus::RolledBack);

    importsPage($this->owner)->assertOk()->assertSee('march.xlsx')->assertSee('Rolled back');
});

it('refuses commit and rollback while GoProperty staff impersonate, but shows them the results', function () {
    $passed = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    $committed = committedImport($this->acme, $this->owner);
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $passedPage = asSupport()->get("/o/acme-homes/imports/{$passed->id}")->assertOk()->assertSee('Dry run')->assertDontSee('data-test="commit-import"', false);
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($passedPage), [], [['commit']])->assertForbidden();

    $committedPage = asSupport()->get("/o/acme-homes/imports/{$committed->id}")->assertOk()->assertSee('Import report')->assertDontSee('data-test="rollback-import"', false);
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($committedPage), [], [['rollback']])->assertForbidden();

    // The policy says the same, whatever the permission classification.
    expect(inAcme($this->acme, fn () => Gate::forUser($this->owner)->allows('commit', $passed)))->toBeFalse()
        ->and(inAcme($this->acme, fn () => Gate::forUser($this->owner)->allows('rollback', $committed)))->toBeFalse();

    expect(freshBatch($this->acme, $passed)->status)->toBe(ImportStatus::DryRunPassed)
        ->and(freshBatch($this->acme, $committed)->status)->toBe(ImportStatus::Committed)
        ->and(contactsOf($this->acme)->whereNull('deleted_at'))->toHaveCount(3)
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(2);
});

it('keeps imports read-only while the organization is suspended', function () {
    $passed = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    $page = importsPage($this->owner, "/{$passed->id}")->assertOk();
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    importsPage($this->owner)->assertOk()->assertDontSee('data-test="new-import"', false);
    importsPage($this->owner, '/new')->assertForbidden();
    importsPage($this->owner, "/{$passed->id}")->assertOk()->assertDontSee('data-test="commit-import"', false);
    LivewireHttp::update($this, LivewireHttp::snapshot($page), [], [['commit']])->assertForbidden();

    expect(freshBatch($this->acme, $passed)->status)->toBe(ImportStatus::DryRunPassed);
});

it('answers 404 for another organization\'s import guessed by ID', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $theirs = dryRunImport($other, $otherOwner, importWorkbook(['Contacts' => contactRows(2)]));

    importsPage($this->owner, "/{$theirs->id}")->assertNotFound();
    importsPage($this->owner, "/{$theirs->id}/problems")->assertNotFound();

    // A member of both still gets 404 inside the other workspace.
    joinAs($this->owner, $other, OrganizationRole::Admin);
    importsPage($this->owner, "/{$theirs->id}")->assertNotFound();
    importsPage($this->owner, "/{$theirs->id}", 'other-realty')->assertOk();
    expect(inAcme($this->acme, fn () => ImportBatch::query()->find($theirs->id)))->toBeNull();
});
