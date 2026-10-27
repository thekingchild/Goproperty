<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Exports\ListExportRefused;
use App\Exports\ListExportRequests;
use App\Exports\ListExports;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Unit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Fixtures\Exports\ProbeList;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P02-07: who may export, and the record of it. Only members who can see
 * the list export it; the download is a one-time ticket for that member in
 * that organization; every export is audited with its filters and row
 * count; exports are rate-limited and capped in size; they keep working in
 * a suspended organization and in a support session, except lists of
 * people's contact details, which staff never take away
 * (PLATFORM_CONSOLE.md § 6).
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    joinAs($this->owner, $this->other);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);

    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    inOrganization($this->acme, function (): void {
        Unit::factory()->in($this->palm)->create(['unit_label' => 'Flat 1', 'status' => UnitStatus::Occupied]);
        Unit::factory()->in($this->palm)->create(['unit_label' => 'Flat 2', 'status' => UnitStatus::Vacant]);
        Unit::factory()->in($this->palm)->create(['unit_label' => 'Flat 3', 'status' => UnitStatus::Occupied]);
    });
    ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348031234567']);
});

afterEach(fn () => freshRequest());

/** Exports recorded in the audit log, oldest first. */
function exportEntries(): Collection
{
    return AuditEntry::query()->where('log_name', 'exports')->orderBy('id')->get();
}

/** Presses Export on a page in the support session. */
function supportPressExport(string $path, string $list, string $format = 'csv'): TestResponse
{
    $page = asSupport()->get("/o/acme-homes{$path}")->assertOk();

    return LivewireHttp::update(asSupport(), LivewireHttp::snapshot($page), [], [['export', [$list, $format]]]);
}

it('audits every export: who, which list and format, the filters and the row count', function () {
    exportRows($this->owner, '/units', 'units', 'csv', ['status' => 'occupied', 'search' => 'Flat']);
    exportRows($this->owner, '/owners', 'owners', 'xlsx');

    $entries = exportEntries();
    expect($entries)->toHaveCount(2);

    [$units, $owners] = [$entries[0], $entries[1]];
    expect($units->event)->toBe('list.exported')
        ->and($units->causer_id)->toBe($this->owner->id)
        ->and($units->causer_type)->toBe($this->owner->getMorphClass())
        ->and($units->subject_type)->toBe($this->acme->getMorphClass())
        ->and($units->subject_id)->toBe($this->acme->id)
        ->and($units->description)->toBe('Exported Units (CSV, 2 rows)')
        ->and($units->properties->all())->toEqual([
            'list' => 'units',
            'format' => 'csv',
            'filters' => ['search' => 'Flat', 'status' => 'occupied'],
            'rows' => 2,
        ])
        ->and($units->impersonator_staff_id)->toBeNull()
        ->and($units->hash)->not->toBeNull()
        ->and($owners->description)->toBe('Exported Owners (Excel, 1 row)')
        ->and($owners->getProperty('filters'))->toBe([]);

    // Pressing Export alone writes nothing: the entry comes with the download.
    pressExport($this->owner, '/units', 'units')->assertOk();
    expect(exportEntries())->toHaveCount(2);
});

it('hands out a one-time download for this member in this organization only, valid five minutes', function () {
    $url = LivewireHttp::redirect(pressExport($this->owner, '/units', 'units')->assertOk());
    expect($url)->toMatch('#/o/acme-homes/exports/[A-Za-z0-9]{40}$#')
        // The filters stay on the server.
        ->and($url)->not->toContain('status');

    // Not another member's.
    exportsFreshRequest();
    $this->actingAs($this->agent)->get($url)->assertNotFound();

    // Not in the member's other organization.
    exportsFreshRequest();
    $this->actingAs($this->owner)->get(str_replace('/o/acme-homes/', '/o/other-realty/', (string) $url))->assertNotFound();

    // Once.
    exportsFreshRequest();
    $this->actingAs($this->owner)->get($url)->assertOk();
    exportsFreshRequest();
    $this->actingAs($this->owner)->get($url)->assertNotFound();

    // Within five minutes.
    $late = LivewireHttp::redirect(pressExport($this->owner, '/units', 'units')->assertOk());
    $this->travel(ListExportRequests::TICKET_SECONDS + 1)->seconds();
    exportsFreshRequest();
    $this->actingAs($this->owner)->get($late)->assertNotFound();

    // Signed out: to the sign-in page, no file.
    $ticket = LivewireHttp::redirect(pressExport($this->owner, '/units', 'units')->assertOk());
    exportsFreshRequest();
    auth()->guard('web')->logout();
    $this->get($ticket)->assertRedirect();

    expect(exportEntries())->toHaveCount(1);
});

it('lets only members who may see the list export it, and checks again at the download', function () {
    // Agents see units and owners but not members or imports.
    expect(exportRows($this->agent, '/units', 'units'))->toHaveCount(4);
    inOrganization($this->acme, fn () => expect(fn () => app(ListExportRequests::class)->start($this->agent, 'members', 'csv', []))
        ->toThrow(AuthorizationException::class));
    inOrganization($this->acme, fn () => expect(fn () => app(ListExportRequests::class)->start($this->agent, 'imports', 'csv', []))
        ->toThrow(AuthorizationException::class));
    // An unknown list or format.
    inOrganization($this->acme, fn () => expect(fn () => app(ListExportRequests::class)->start($this->owner, 'secrets', 'csv', []))
        ->toThrow(NotFoundHttpException::class));

    // The agent loses the units list (no longer an agent here) after pressing Export.
    $url = LivewireHttp::redirect(pressExport($this->agent, '/units', 'units')->assertOk());
    inOrganization($this->acme, fn () => $this->agent->removeRole(OrganizationRole::Agent->value));
    exportsFreshRequest();
    // No longer in the workspace at all: the organization answers 404, no file.
    $refused = $this->actingAs($this->agent)->get($url);
    expect($refused->status())->toBe(404)
        ->and($refused->headers->get('Content-Disposition'))->toBeNull();

    expect(exportEntries())->toHaveCount(1);
});

it('keeps exports working in a suspended organization', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expect(exportRows($this->owner, '/units', 'units'))->toHaveCount(4)
        ->and(exportRows($this->owner, '/owners', 'owners', 'xlsx'))->toHaveCount(2)
        ->and(exportRows($this->owner, '/settings/members', 'members'))->toHaveCount(3);
});

it('lets impersonating staff export lists without contact details, and refuses the rest', function (ImpersonationMode $mode) {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    // A download the owner asked for before the session.
    $ownersTicket = LivewireHttp::redirect(pressExport($this->owner, '/owners', 'owners')->assertOk());
    freshRequest();

    $handOff = startSupport($staff, $this->owner, $this->acme, $mode);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    // Units and buildings: allowed, and audited with both identities.
    $units = openDownloadAsSupport(supportPressExport('/units', 'units'));
    expect(fileRows($units->streamedContent(), 'csv'))->toHaveCount(4);
    openDownloadAsSupport(supportPressExport('/buildings', 'buildings', 'xlsx'))->assertOk();
    $entry = exportEntries()->last();
    expect($entry->impersonator_staff_id)->toBe($staff->id)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->getProperty('impersonation_session_id'))->toBe($handOff->session->id);

    // Owners and members: the pages open, but no copy leaves (403, audited).
    $page = (string) asSupport()->get('/o/acme-homes/owners')->assertOk()->getContent();
    expect($page)->not->toContain('data-test="export-owners-csv"');
    supportPressExport('/owners', 'owners')->assertForbidden();
    asSupport()->get('/o/acme-homes/settings/members')->assertOk()->assertDontSee('data-test="export-members-csv"', false);
    supportPressExport('/settings/members', 'members')->assertForbidden();
    asSupport()->get($ownersTicket)->assertForbidden();

    expect(exportEntries())->toHaveCount(2)
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->count())->toBe(3);
})->with([ImpersonationMode::ReadOnly, ImpersonationMode::Write]);

/** Opens, in the support session, the download the Export button redirected to. */
function openDownloadAsSupport(TestResponse $pressed): TestResponse
{
    $url = LivewireHttp::redirect($pressed->assertOk());
    expect($url)->toBeString();

    return asSupport()->get((string) $url)->assertOk();
}

it('limits how many exports a member makes in an hour', function () {
    $key = 'list-exports:'.$this->acme->id.':'.$this->owner->id;

    foreach (range(1, ListExportRequests::PER_HOUR) as $i) {
        RateLimiter::hit($key, 3600);
    }

    $pressed = pressExport($this->owner, '/units', 'units')->assertOk();
    expect(LivewireHttp::redirect($pressed))->toBeNull()
        ->and(LivewireHttp::html($pressed))->toContain('data-test="export-error"')
        ->toContain('You have exported a lot in the last hour. Try again in 60 minutes.');

    // Another member is not affected.
    expect(exportRows($this->agent, '/units', 'units'))->toHaveCount(4);
    expect(exportEntries())->toHaveCount(1);
});

it('refuses a list too long for one export, asking to narrow it', function () {
    app(ListExports::class)->register(ProbeList::class);
    ProbeList::$rows = ListExportRequests::MAX_ROWS + 1;

    try {
        $this->actingAs($this->owner);
        inOrganization($this->acme, fn () => expect(fn () => app(ListExportRequests::class)->start($this->owner, 'probe_list', 'csv', []))
            ->toThrow(ListExportRefused::class, 'This list has 50,001 rows; one export holds at most 50,000.'));

        ProbeList::$rows = 3;
        $url = inOrganization($this->acme, fn () => app(ListExportRequests::class)->start($this->owner, 'probe_list', 'csv', []));
        exportsFreshRequest();
        expect(csvRows($this->actingAs($this->owner)->get($url)->assertOk()->streamedContent()))->toBe([['Number'], ['1'], ['2'], ['3']]);
    } finally {
        ProbeList::$rows = 3;
    }
});
