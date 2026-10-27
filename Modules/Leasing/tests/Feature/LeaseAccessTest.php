<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Enums\UnitStatus;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-03a: who may see and change leases (ARCHITECTURE.md § 5,
 * LeasePolicy): owners, administrators and agents; another organization's
 * lease guessed by ID answers 404 everywhere; an agent assigned to
 * buildings sees only those buildings' leases (decision 9, BuildingAccess);
 * a suspended organization is read-only; impersonating staff change
 * nothing in a read-only session and never settle a deposit.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']);
    $this->active = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $this->draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']));
    $this->ended = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3']), tenantIn($this->acme));
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->ended, 'Moved out'));

    $this->foreignUnit = unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court']));
    $this->foreignTenant = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant']);
    $this->foreign = activeLease($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant);
    $this->foreignDraft = draftLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other)), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Draft']));
});

afterEach(fn () => leasesFreshRequest());

/** @return array<string, bool> ability => allowed, as LeasePolicy answers inside the organization */
function leaseAbilitiesOf(User $user, Organization $organization, Lease $active, Lease $draft, Lease $ended): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', Lease::class),
        'view' => Gate::forUser($user)->allows('view', $active),
        'export' => Gate::forUser($user)->allows('export', Lease::class),
        'create' => Gate::forUser($user)->allows('create', Lease::class),
        'update' => Gate::forUser($user)->allows('update', $draft),
        'activate' => Gate::forUser($user)->allows('activate', $draft),
        'renew' => Gate::forUser($user)->allows('renew', $active),
        'terminate' => Gate::forUser($user)->allows('terminate', $active),
        'settleDeposit' => Gate::forUser($user)->allows('settleDeposit', storedLease($organization, $ended)),
        'updateActive' => Gate::forUser($user)->allows('update', $active),
        'activateActive' => Gate::forUser($user)->allows('activate', $active),
    ]);
}

it('enforces the ARCHITECTURE.md § 5 role rules in LeasePolicy', function (OrganizationRole $role, bool $allowed) {
    $user = joinAs(User::factory()->create(), $this->acme, $role);
    $all = array_fill_keys(['viewAny', 'view', 'export', 'create', 'update', 'activate', 'renew', 'terminate', 'settleDeposit'], $allowed);

    expect(leaseAbilitiesOf($user, $this->acme, $this->active, $this->draft, $this->ended))
        ->toBe($all + ['updateActive' => false, 'activateActive' => false]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('opens the lease pages for owners, administrators and agents, and 404 for property owners, tenants and strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    foreach ([$this->owner, $admin, $agent] as $user) {
        leasesPage($user)->assertOk()->assertSee('Kemi Adebayo')->assertSee('data-test="add-lease"', false)->assertSee('data-test="export-leases-csv"', false)->assertSee('data-nav="leases"', false);
        leasesPage($user, '/create')->assertOk();
        leasesPage($user, "/{$this->active->id}")->assertOk()->assertSee('data-test="renew-lease"', false)->assertSee('data-test="terminate-lease"', false);
        leasesPage($user, "/{$this->draft->id}")->assertOk()->assertSee('data-test="activate-lease"', false)->assertSee('data-test="edit-lease"', false);
        leasesPage($user, "/{$this->draft->id}/edit")->assertOk();
        leasesPage($user, "/{$this->active->id}/renew")->assertOk();
    }

    // An agent signs a lease, audited as themselves.
    leasesUpdate($agent, leasesSnapshot($agent, "/{$this->draft->id}"), [], [['activate']])->assertOk();
    expect(AuditEntry::query()->where('event', 'lease.activated')->latest('id')->first()->causer_id)->toBe($agent->id);

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        leasesPage($user)->assertNotFound();
        leasesPage($user, '/create')->assertNotFound();
        leasesPage($user, "/{$this->active->id}")->assertNotFound();
    }
});

it('answers 404 for another organization\'s lease guessed by ID, on every page', function () {
    foreach ([$this->foreign, $this->foreignDraft] as $lease) {
        foreach (['', '/edit', '/renew'] as $page) {
            leasesPage($this->owner, "/{$lease->id}{$page}")->assertNotFound();
        }
    }

    leasesPage($this->owner, '/999999')->assertNotFound();
    leasesPage($this->owner, '/abc')->assertNotFound();

    // Never listed, searched or filtered into view.
    foreach (['', '?q=Foreign', '?q='.urlencode((string) $this->foreign->contract_number), '?status=draft', '?building='.$this->foreignUnit->property_id] as $query) {
        leasesPage($this->owner, $query)->assertOk()->assertDontSee('Foreign Tenant')->assertDontSee('Foreign Draft')->assertDontSee('data-lease="'.$this->foreign->id.'"', false);
    }

    // A member of both organizations sees only the one they are working in.
    joinAs($this->owner, $this->other);
    expect(listedLeases(leasesPage($this->owner)))->toEqualCanonicalizing([$this->active->id, $this->draft->id, $this->ended->id])
        ->and(listedLeases(leasesPage($this->owner, '', 'other-realty')))->toEqualCanonicalizing([$this->foreign->id, $this->foreignDraft->id]);
});

it('answers 404 for another organization\'s lease, unit or tenant on every action sent straight to the server', function () {
    // Livewire answers a tampered snapshot with 419 in production (debug off).
    config(['app.debug' => false]);
    $tamper = function (string $snapshot, string $property, int $id): string {
        $data = json_decode($snapshot, true);
        $data['data'][$property] = $id;

        return (string) json_encode($data);
    };

    leasesUpdate($this->owner, $tamper(leasesSnapshot($this->owner, "/{$this->draft->id}"), 'leaseId', $this->foreignDraft->id), [], [['activate']])->assertStatus(419);
    leasesUpdate($this->owner, $tamper(leasesSnapshot($this->owner, "/{$this->active->id}"), 'leaseId', $this->foreign->id), ['reason' => 'Hijack'], [['terminate']])->assertStatus(419);
    leasesUpdate($this->owner, $tamper(leasesSnapshot($this->owner, "/{$this->draft->id}/edit"), 'leaseId', $this->foreignDraft->id), ['rent' => '1'], [['save']])->assertStatus(419);
    leasesUpdate($this->owner, $tamper(leasesSnapshot($this->owner, "/{$this->active->id}/renew"), 'renewingId', $this->foreign->id), [], [['save']])->assertStatus(419);

    // The writer itself refuses a foreign lease handed to it.
    $service = app(LeaseService::class);
    expect(fn () => inOrganization($this->acme, fn () => $service->terminate($this->owner, $this->foreign, 'Hijack')))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $service->activate($this->owner, $this->foreignDraft)))->toThrow(AuthorizationException::class);

    // A foreign unit or tenant on the new-lease form: not found here.
    $form = leasesSnapshot($this->owner, '/create');
    $response = leasesUpdate($this->owner, $form, ['tenantId' => (string) $this->foreignTenant->id, 'unitId' => (string) $this->flat->id] + leaseInput(), [['save']])->assertOk();
    expect(LivewireHttp::errors($response))->toHaveKey('tenantId');
    $response = leasesUpdate($this->owner, $form, ['tenantId' => (string) $this->kemi->id, 'unitId' => (string) $this->foreignUnit->id] + leaseInput(), [['save']])->assertOk();
    expect(LivewireHttp::errors($response))->toHaveKey('unitId');

    // Nothing changed on either side.
    expect(leasesOf($this->acme))->toHaveCount(3)
        ->and(storedLease($this->other, $this->foreign)->status)->toBe(LeaseStatus::Active)
        ->and(storedLease($this->other, $this->foreignDraft)->status)->toBe(LeaseStatus::Draft)
        ->and(unitStatusOf($this->other, $this->foreignUnit))->toBe(UnitStatus::Occupied);
});

it('shows an agent assigned to buildings only those buildings\' leases, and 404 for the others', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $oakUnit = unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']);
    $oakLease = activeLease($this->acme, $this->owner, $oakUnit, tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Tenant']));
    $oakDraft = draftLease($this->acme, $this->owner, unitIn($this->acme, $oak), tenantIn($this->acme));
    assignIn($this->acme, $agent, $this->palm);

    expect(listedLeases(leasesPage($agent)))->toEqualCanonicalizing([$this->active->id, $this->draft->id, $this->ended->id])
        ->and(listedLeases(leasesPage($agent, '?building='.$oak->id)))->toBe([]);
    leasesPage($agent)->assertDontSee('Oak Lodge');

    foreach (['', '/edit', '/renew'] as $page) {
        leasesPage($agent, "/{$oakLease->id}{$page}")->assertNotFound();
        leasesPage($agent, "/{$oakDraft->id}{$page}")->assertNotFound();
    }

    $service = app(LeaseService::class);
    $refusedAs404 = fn (Closure $action) => expect(fn () => inOrganization($this->acme, $action))
        ->toThrow(fn (AuthorizationException $e) => expect($e->status())->toBe(404));

    $refusedAs404(fn () => $service->terminate($agent, $oakLease, 'Not mine'));
    $refusedAs404(fn () => $service->activate($agent, $oakDraft));
    $refusedAs404(fn () => $service->draft($agent, ['tenantId' => $this->kemi->id, 'unitId' => unitIn($this->acme, $oak)->id] + leaseInput()));

    // The form offers the agent's buildings only.
    leasesPage($agent, '/create')->assertOk()->assertSee('Palm Court')->assertDontSee('Oak Lodge');

    // Their own building's leases they work on.
    leasesUpdate($agent, leasesSnapshot($agent, "/{$this->draft->id}"), [], [['activate']])->assertOk();
    expect(storedLease($this->acme, $this->draft)->status)->toBe(LeaseStatus::Active)
        ->and(storedLease($this->acme, $oakLease)->status)->toBe(LeaseStatus::Active)
        ->and(storedLease($this->acme, $oakDraft)->status)->toBe(LeaseStatus::Draft);

    // An agent with no assignment sees every building's leases.
    $free = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    expect(listedLeases(leasesPage($free)))->toHaveCount(5);
});

it('shows leases read-only while the organization is suspended, and still exports them', function () {
    $show = leasesSnapshot($this->owner, "/{$this->draft->id}");
    $active = leasesSnapshot($this->owner, "/{$this->active->id}");
    $ended = leasesSnapshot($this->owner, "/{$this->ended->id}");
    $edit = leasesSnapshot($this->owner, "/{$this->draft->id}/edit");
    $create = leasesSnapshot($this->owner, '/create');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    leasesPage($this->owner)->assertOk()->assertSee('Kemi Adebayo')->assertDontSee('data-test="add-lease"', false)->assertSee('data-test="export-leases-csv"', false);
    leasesPage($this->owner, "/{$this->draft->id}")->assertOk()->assertDontSee('data-test="activate-lease"', false)->assertDontSee('data-test="edit-lease"', false)->assertDontSee('data-test="terminate-lease"', false);
    leasesPage($this->owner, "/{$this->active->id}")->assertOk()->assertDontSee('data-test="renew-lease"', false);
    leasesPage($this->owner, '/create')->assertForbidden();
    leasesPage($this->owner, "/{$this->draft->id}/edit")->assertForbidden();
    leasesPage($this->owner, "/{$this->active->id}/renew")->assertForbidden();

    leasesUpdate($this->owner, $show, [], [['activate']])->assertForbidden();
    leasesUpdate($this->owner, $active, ['reason' => 'Moved out'], [['terminate']])->assertForbidden();
    leasesUpdate($this->owner, $ended, ['refunded' => '150,000'], [['settleDeposit']])->assertForbidden();
    leasesUpdate($this->owner, $edit, ['rent' => '2,000,000'], [['save']])->assertForbidden();
    leasesUpdate($this->owner, $create, ['tenantId' => (string) $this->kemi->id, 'unitId' => (string) unitIn($this->acme, $this->palm)->id] + leaseInput(), [['save']])->assertForbidden();

    expect(storedLease($this->acme, $this->draft)->status)->toBe(LeaseStatus::Draft)
        ->and(storedLease($this->acme, $this->active)->status)->toBe(LeaseStatus::Active)
        ->and(leasesOf($this->acme))->toHaveCount(3)
        ->and(depositEntriesOf($this->acme, $this->ended))->toBe([['held', 15_000_000]]);

    // The daily job still moves dates on (no member acts): see LeaseExpiryJobTest. Exports are reads.
    $pressed = leasesUpdate($this->owner, leasesSnapshot($this->owner), [], [['export', ['leases', 'csv']]])->assertOk();
    expect(LivewireHttp::redirect($pressed))->toContain('/exports/');
});

it('shows impersonating staff the leases read-only in a read-only session, and lets them export the list', function () {
    $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme))->assertRedirect('/o/acme-homes');

    $list = asSupport()->get('/o/acme-homes/leases')->assertOk()->assertSee('Kemi Adebayo')->assertDontSee('data-test="add-lease"', false)->assertSee('data-test="export-leases-csv"', false);
    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/leases/{$this->draft->id}")->assertOk()->assertDontSee('data-test="activate-lease"', false));
    asSupport()->get('/o/acme-homes/leases/create')->assertForbidden();

    freshRequest();
    LivewireHttp::update(test(), $show, [], [['activate']])->assertForbidden();
    freshRequest();
    $pressed = LivewireHttp::update(test(), LivewireHttp::snapshot($list), [], [['export', ['leases', 'csv']]])->assertOk();

    expect(LivewireHttp::redirect($pressed))->toContain('/exports/')
        ->and(storedLease($this->acme, $this->draft)->status)->toBe(LeaseStatus::Draft);
});

it('lets impersonating staff in write mode draft and activate leases, audited with both identities, but never settle a deposit', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $show = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/leases/{$this->draft->id}")->assertOk()->assertSee('data-test="activate-lease"', false));
    freshRequest();
    LivewireHttp::update(test(), $show, [], [['activate']])->assertOk();

    $ended = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/leases/{$this->ended->id}")->assertOk()->assertDontSee('data-test="settle-deposit"', false));
    freshRequest();
    LivewireHttp::update(test(), $ended, ['refunded' => '150,000'], [['settleDeposit']])->assertForbidden();

    $activation = AuditEntry::query()->where('event', 'lease.activated')->where('subject_id', $this->draft->id)->sole();
    expect(storedLease($this->acme, $this->draft)->status)->toBe(LeaseStatus::Active)
        ->and($activation->causer_id)->toBe($this->owner->id)
        ->and($activation->impersonator_staff_id)->toBe($staff->id)
        ->and(depositEntriesOf($this->acme, $this->ended))->toBe([['held', 15_000_000]])
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
});
