<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Enums\ImportStatus;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Events\UnitDeleted;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P02-06c (IMPLEMENTATION_PLAN.md P02-06 AC): owners, buildings and units
 * arrive by import with the tenants, as one 2,000-row workbook through the
 * real engine (upload → dry run → queued commit → rollback). The fixture
 * workbook is generated here: 100 owners, 200 buildings, 1,500 units and
 * 200 tenants.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('rolls back the batch: what it created or restored is archived, everything else stays', function () {
    Event::fake([UnitCreated::class, UnitDeleted::class]);

    // Already on file before the import: an owner the import updates and a
    // building of the organization's own with a unit.
    $known = ownerIn($this->acme, ['first_name' => 'Owner3', 'last_name' => 'Adeyemi', 'phone' => portfolioOwnerPhone(3), 'notes' => 'Kept']);
    $own = buildingIn($this->acme, ['name' => 'Head Office']);
    unitIn($this->acme, $own, ['unit_label' => 'Suite 1']);

    $batch = portfolioImport($this->acme, $this->owner, portfolioWorkbook(portfolioFixture()));
    expect(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1501);

    $batch = portfolioRollback($this->acme, $this->owner, $batch);

    $owners = ownersOf($this->acme);
    $buildings = buildingsOf($this->acme);
    $units = unitsOf($this->acme);

    expect($batch->status)->toBe(ImportStatus::RolledBack)
        // Everything the batch created is archived (soft deleted), nothing hard deleted.
        ->and($owners)->toHaveCount(100)
        ->and($owners->filter->trashed()->pluck('import_batch_id')->unique()->values()->all())->toBe([$batch->id])
        ->and($owners->filter->trashed())->toHaveCount(99)
        ->and($buildings->filter->trashed())->toHaveCount(200)
        ->and($units->filter->trashed())->toHaveCount(1500)
        ->and(inOrganization($this->acme, fn () => TenantProfile::query()->count()))->toBe(0)
        // The updated owner keeps the import's changes; the organization's own building and unit stay.
        ->and($owners->firstWhere('id', $known->id)->trashed())->toBeFalse()
        ->and($owners->firstWhere('id', $known->id)->external_ref)->toBe('O-3')
        ->and($buildings->firstWhere('id', $own->id)->trashed())->toBeFalse()
        ->and($units->where('property_id', $own->id)->first()->trashed())->toBeFalse()
        ->and(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1)
        ->and(AuditEntry::query()->where('log_name', 'imports')->where('event', 'import.rolled_back')->sole()->properties['archived'])
        ->toEqual(['tenants' => 200, 'units' => 1500, 'properties' => 200, 'owners' => 99]);

    // The units leave the bill, as when archived on the pages (P06 metering).
    Event::assertDispatchedTimes(UnitDeleted::class, 1500);

    // Importing the file again brings the same records back instead of colliding.
    $again = portfolioImport($this->acme, $this->owner, portfolioWorkbook(portfolioFixture()));

    expect($again->result_counts['targets']['owners'])->toEqual(['create' => 0, 'update' => 1, 'restore' => 99, 'skip' => 0])
        ->and($again->result_counts['targets']['properties'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 200, 'skip' => 0])
        ->and($again->result_counts['targets']['units'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 1500, 'skip' => 0])
        ->and(array_map('count', portfolioTables()))->toEqual(['owner_profiles' => 100, 'properties' => 201, 'units' => 1501, 'tenant_profiles' => 200])
        ->and(unitsOf($this->acme)->filter->trashed())->toHaveCount(0)
        ->and(unitsOf($this->acme)->where('import_batch_id', $again->id))->toHaveCount(1500);
});

it('restores archived owners, buildings and units it matches, audited, and a rollback archives them again', function () {
    $owner = ownerIn($this->acme, ['first_name' => 'Owner1', 'last_name' => 'Adeyemi', 'phone' => portfolioOwnerPhone(1)]);
    inOrganization($this->acme, fn () => $owner->delete());
    // A building an earlier import's rollback removed, with its unit.
    $building = buildingIn($this->acme, ['name' => 'Building 1', 'owner_profile_id' => $owner->id]);
    $unit = unitIn($this->acme, $building, ['unit_label' => 'flat 1', 'base_rent_kobo' => Money::naira(800_000)]);
    inOrganization($this->acme, function () use ($building, $unit): void {
        $unit->delete();
        $building->delete();
    });
    // A unit archived on the pages, in a live building.
    $live = buildingIn($this->acme, ['name' => 'Building 2']);
    $archived = unitIn($this->acme, $live, ['unit_label' => 'Flat 1', 'status' => UnitStatus::Unavailable]);
    inOrganization($this->acme, fn () => $archived->delete());

    $sheets = [
        'Owners' => portfolioOwnerRows(1),
        'Buildings' => portfolioBuildingRows(2, 1),
        'Units' => portfolioUnitRows(2, 1),
    ];
    $sheets['Buildings'][2][2] = '';  // Building 2 keeps having no owner
    $sheets['Units'][1][0] = '';      // matched by building + label ("flat 1" on file)
    $sheets['Units'][2][0] = '';

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook($sheets));

    expect($batch->dry_run_report['counts'])->toEqual(['create' => 0, 'update' => 1, 'restore' => 4, 'skip' => 0])
        ->and($batch->dry_run_report['units'])->toBe(2)
        ->and(portfolioIssues($batch, 'owners'))->toBe([[2, null, null, 'warning', 'This owner was archived. Importing brings them back.']])
        ->and(portfolioIssues($batch, 'properties'))->toBe([[2, null, null, 'warning', 'This building was removed when an import was rolled back. Importing brings it back.']])
        ->and(portfolioIssues($batch, 'units'))->toBe([
            [2, null, null, 'warning', 'This unit was archived. Importing brings it back, and it counts towards your bill again.'],
            [2, 'Rent per year', 'I', 'warning', 'The rent on file is ₦800,000 a year; this import changes it to ₦1,001,000.'],
            [2, null, null, 'warning', 'Each unit this import adds or brings back counts towards your GoProperty bill. You have 0 billable units now; the summary shows the total and the monthly price with this file\'s units.'],
            [3, null, null, 'warning', 'This unit was archived. Importing brings it back, and it counts towards your bill again.'],
            [3, 'Rent per year', 'I', 'warning', 'The rent on file is ₦1,500,000 a year; this import changes it to ₦1,001,000.'],
        ]);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    $restoredUnits = unitsOf($this->acme);
    expect($batch->result_counts['targets']['owners'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 1, 'skip' => 0])
        ->and($batch->result_counts['targets']['properties'])->toEqual(['create' => 0, 'update' => 1, 'restore' => 1, 'skip' => 0])
        ->and($batch->result_counts['targets']['units'])->toEqual(['create' => 0, 'update' => 0, 'restore' => 2, 'skip' => 0])
        ->and(ownersOf($this->acme)->sole()->trashed())->toBeFalse()
        ->and(ownersOf($this->acme)->sole()->import_batch_id)->toBe($batch->id)
        ->and(buildingsOf($this->acme)->firstWhere('id', $building->id)->trashed())->toBeFalse()
        ->and(buildingsOf($this->acme)->firstWhere('id', $building->id)->import_batch_id)->toBe($batch->id)
        // Updated, not restored: keeps its provenance.
        ->and(buildingsOf($this->acme)->firstWhere('id', $live->id)->import_batch_id)->toBeNull()
        ->and($restoredUnits)->toHaveCount(2)
        ->and($restoredUnits->filter->trashed())->toHaveCount(0)
        // The label as the file has it, the status the archived unit had.
        ->and($restoredUnits->firstWhere('id', $unit->id)->unit_label)->toBe('Flat 1')
        ->and($restoredUnits->firstWhere('id', $archived->id)->status)->toBe(UnitStatus::Unavailable)
        ->and(AuditEntry::query()->where('log_name', 'owners')->where('event', 'owner.restored')->sole()->properties['import_batch_id'])->toBe($batch->id)
        ->and(AuditEntry::query()->where('log_name', 'properties')->where('event', 'property.restored')->sole()->subject_id)->toBe($building->id)
        ->and(AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.restored')->pluck('subject_id')->sort()->values()->all())->toBe([$unit->id, $archived->id]);

    portfolioRollback($this->acme, $this->owner, $batch);

    expect(ownersOf($this->acme)->sole()->trashed())->toBeTrue()
        ->and(buildingsOf($this->acme)->firstWhere('id', $building->id)->trashed())->toBeTrue()
        ->and(buildingsOf($this->acme)->firstWhere('id', $live->id)->trashed())->toBeFalse()
        ->and(unitsOf($this->acme)->filter->trashed())->toHaveCount(2);
});
