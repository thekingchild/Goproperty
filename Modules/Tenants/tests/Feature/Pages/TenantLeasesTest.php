<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportRefused;
use Modules\Leasing\Leases\LeaseService;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../Leasing/tests/Feature/helpers.php';
require_once __DIR__.'/../../../../../tests/Feature/Exports/helpers.php';

/*
 * P03-03a in the Tenants module: leases tie tenants to buildings, read
 * through the Leasing module's Contracts\TenantLeases. An agent assigned
 * to buildings sees the tenants with no lease and the tenants of their
 * buildings (TenantNarrowing, the P03-01a hook); a tenant whose lease
 * still holds a unit is not archived; the tenant's record lists their
 * leases; other modules read tenants through Contracts\TenantDirectory.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    assignIn($this->acme, $this->agent, $this->palm);

    $this->palmTenant = tenantIn($this->acme, ['first_name' => 'Palm', 'last_name' => 'Renter']);
    $this->oakTenant = tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Renter']);
    $this->bothTenant = tenantIn($this->acme, ['first_name' => 'Both', 'last_name' => 'Renter']);
    $this->pastTenant = tenantIn($this->acme, ['first_name' => 'Past', 'last_name' => 'Renter']);
    $this->newTenant = tenantIn($this->acme, ['first_name' => 'New', 'last_name' => 'Renter']);

    $this->palmLease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Palm 1']), $this->palmTenant);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']), $this->oakTenant);
    draftLease($this->acme, $this->owner, unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 2']), $this->bothTenant);
    draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Palm 2']), $this->bothTenant);
    $past = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Palm 3']), $this->pastTenant);
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $past, 'Moved out'));
});

afterEach(fn () => tenantPagesFreshRequest());

/** The tenant names a rendered tenants list or export shows. */
function namesIn(string $html, array $tenants): array
{
    return array_values(array_map(fn (TenantProfile $tenant): string => $tenant->fullName(), array_filter($tenants, fn (TenantProfile $tenant): bool => str_contains($html, $tenant->fullName()))));
}

it('shows an agent assigned to buildings the tenants without a lease and the tenants of their buildings, on the list, the export and the record pages', function () {
    $everyone = [$this->palmTenant, $this->oakTenant, $this->bothTenant, $this->pastTenant, $this->newTenant];

    expect(namesIn((string) tenantsPage($this->agent)->assertOk()->getContent(), $everyone))->toBe(['Palm Renter', 'Both Renter', 'Past Renter', 'New Renter'])
        ->and(namesIn((string) tenantsPage($this->owner)->assertOk()->getContent(), $everyone))->toHaveCount(5);

    $exported = array_map(fn (array $row): string => $row[1].' '.$row[2], array_slice(exportRows($this->agent, '/tenants', 'tenants'), 1));
    expect($exported)->toEqualCanonicalizing(['Palm Renter', 'Both Renter', 'Past Renter', 'New Renter']);

    foreach ([$this->palmTenant, $this->bothTenant, $this->pastTenant, $this->newTenant] as $tenant) {
        tenantsPage($this->agent, "/{$tenant->id}")->assertOk();
    }

    tenantsPage($this->agent, "/{$this->oakTenant->id}")->assertNotFound();
    tenantsPage($this->agent, "/{$this->oakTenant->id}/edit")->assertNotFound();
    LivewireHttp::update($this, tenantsSnapshot($this->agent), [], [['archive', [$this->oakTenant->id]]])->assertNotFound();

    // The new-lease form offers the same tenants.
    $options = inOrganization($this->acme, fn () => app(TenantDirectory::class)->activeOptions($this->agent));
    expect(array_keys($options))->toEqualCanonicalizing([$this->palmTenant->id, $this->bothTenant->id, $this->pastTenant->id, $this->newTenant->id])
        ->and(inOrganization($this->acme, fn () => app(TenantDirectory::class)->find($this->oakTenant->id, $this->agent)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(TenantDirectory::class)->find($this->oakTenant->id)?->name))->toBe('Oak Renter');
});

it('lists the tenant\'s leases on their record page, in the member\'s buildings only', function () {
    $owner = (string) tenantsPage($this->owner, "/{$this->bothTenant->id}")->assertOk()->assertSee('data-test="add-lease-for-tenant"', false)->getContent();
    $agent = (string) tenantsPage($this->agent, "/{$this->bothTenant->id}")->assertOk()->getContent();

    expect(substr_count($owner, 'data-tenant-lease='))->toBe(2)
        ->and($owner)->toContain('Oak 2')->toContain('Palm 2')
        ->and(substr_count($agent, 'data-tenant-lease='))->toBe(1)
        ->and($agent)->toContain('Palm 2')->not->toContain('Oak 2');

    tenantsPage($this->owner, "/{$this->palmTenant->id}")->assertSee((string) $this->palmLease->contract_number)->assertSee('Active');
    tenantsPage($this->owner, "/{$this->newTenant->id}")->assertSee('No leases yet');
});

it('refuses to archive a tenant while a draft or active lease holds a unit for them', function () {
    foreach ([$this->palmTenant, $this->bothTenant] as $tenant) {
        $show = LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}"), [], [['archive']])->assertOk();
        expect(LivewireHttp::html($show))->toContain('data-test="tenant-warning"')->toContain('has an active or draft lease. End or cancel it before archiving them.');

        $list = LivewireHttp::update($this, tenantsSnapshot($this->owner), [], [['archive', [$tenant->id]]])->assertOk();
        expect(LivewireHttp::html($list))->toContain('data-test="tenants-warning"')->toContain('has an active or draft lease.');
    }

    expect(tenantsOf($this->acme)->filter->trashed())->toHaveCount(0);

    // A tenant whose lease has ended, or who never had one, is archived.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$this->pastTenant->id}"), [], [['archive']])->assertOk();
    LivewireHttp::update($this, tenantsSnapshot($this->owner), [], [['archive', [$this->newTenant->id]]])->assertOk();

    expect(tenantsOf($this->acme)->filter->trashed()->pluck('id')->all())->toEqualCanonicalizing([$this->pastTenant->id, $this->newTenant->id]);
});

it('reads tenants for other modules through TenantDirectory, never across organizations', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $foreign = tenantIn($other, ['first_name' => 'Foreign', 'last_name' => 'Renter', 'phone' => '+2348039999999']);
    inOrganization($this->acme, fn () => $this->newTenant->delete());
    $directory = app(TenantDirectory::class);

    inOrganization($this->acme, function () use ($directory, $foreign): void {
        expect($directory->find($foreign->id))->toBeNull()
            ->and($directory->findMany([$foreign->id, $this->newTenant->id]))->toHaveKeys([$this->newTenant->id])->toHaveCount(1)
            ->and($directory->findMany([$this->newTenant->id])[$this->newTenant->id]->archived)->toBeTrue()
            ->and($directory->search('Renter'))->toHaveCount(5)
            ->and($directory->search('Foreign'))->toBe([])
            ->and($directory->activeOptions($this->owner))->not->toHaveKey($this->newTenant->id);

        DB::transaction(function () use ($directory): void {
            expect($directory->lockActive($this->palmTenant->id)?->name)->toBe('Palm Renter')
                ->and($directory->lockActive($this->newTenant->id))->toBeNull();
        });

        // RefreshDatabase runs each test inside a transaction: step out of it
        // (last, as it drops this test's rows) to see a lock outside any
        // transaction refused.
        $level = DB::transactionLevel();
        DB::rollBack(0);

        try {
            expect(fn () => $directory->lockActive($this->palmTenant->id))->toThrow(LogicException::class, 'Lock a tenant inside a database transaction.');
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    });
});

it('refuses to roll back a tenants import once one of its tenants has a lease', function () {
    Storage::fake('media');
    $batch = tenantsImport($this->acme, $this->owner, tenantsWorkbook(['Tenants' => tenantRows(2)]));
    $imported = tenantsOf($this->acme)->firstWhere('external_ref', 'T-2');
    draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), $imported);

    expect(fn () => tenantsRollback($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Some tenants from this import have been used since')
        ->and(tenantsOf($this->acme)->filter->trashed())->toHaveCount(0);
});
