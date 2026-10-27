<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Enums\ImportStatus;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Events\UnitCreated;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
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

it('commits exactly the records the dry run previewed', function () {
    Event::fake([UnitCreated::class]);

    // Owner 3 is already on file (same phone, no ID): the import updates them.
    $known = ownerIn($this->acme, ['first_name' => 'Owner3', 'last_name' => 'Adeyemi', 'phone' => portfolioOwnerPhone(3), 'email' => null, 'notes' => 'Kept']);

    $batch = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook(portfolioFixture()));
    expect($batch->status)->toBe(ImportStatus::DryRunPassed);
    $report = $batch->dry_run_report;

    $batch = portfolioCommit($this->acme, $this->owner, $batch);

    $owners = ownersOf($this->acme);
    $buildings = buildingsOf($this->acme);
    $units = unitsOf($this->acme);
    $tenants = inOrganization($this->acme, fn () => TenantProfile::query()->withTrashed()->orderBy('id')->get());

    expect($batch->status)->toBe(ImportStatus::Committed)
        ->and($batch->result_counts['skipped'])->toBe([]);

    // Each sheet's counts are the dry run's.
    foreach ($report['sheets'] as $sheet) {
        expect($batch->result_counts['targets'][$sheet['target']])->toBe($sheet['counts']);
    }

    expect(portfolioCounts($batch, 'owners'))->toEqual(['create' => 99, 'update' => 1, 'restore' => 0, 'skip' => 0])
        ->and($owners)->toHaveCount(100)
        ->and($buildings)->toHaveCount(200)
        ->and($units)->toHaveCount(1500)
        ->and($tenants)->toHaveCount(200)
        // Provenance: what the batch created carries it; the updated owner keeps their own (none).
        ->and($owners->where('id', '!=', $known->id)->pluck('import_batch_id')->unique()->values()->all())->toBe([$batch->id])
        ->and($owners->firstWhere('id', $known->id)->import_batch_id)->toBeNull()
        ->and($buildings->pluck('import_batch_id')->unique()->values()->all())->toBe([$batch->id])
        ->and($units->pluck('import_batch_id')->unique()->values()->all())->toBe([$batch->id])
        ->and(collect([$owners, $buildings, $units, $tenants])->flatten(1)->pluck('organization_id')->unique()->values()->all())->toBe([$this->acme->id]);

    // The previewed rows are the records as saved.
    $preview = fn (string $target): array => collect($report['sheets'])->firstWhere('target', $target)['preview'];
    $saved = [
        'owners' => fn (int $n): array => (fn (OwnerProfile $o): array => [$o->display_name, $o->type->label(), $o->phone, $o->email, $o->state])($owners->firstWhere('external_ref', "O-{$n}")),
        'properties' => fn (int $n): array => (fn (Property $p): array => [$p->name, $p->owner?->display_name, $p->property_type->label(), $p->address, $p->state])($buildings->firstWhere('external_ref', "B-{$n}")->load('owner')),
        'units' => fn (int $n): array => (fn (Unit $u): array => [$u->property->name, $u->unit_label, $u->unit_type->label(), (string) $u->bedrooms, $u->base_rent_kobo->format(), $u->service_charge_kobo->format(), $u->status->label()])(inOrganization($this->acme, fn () => Unit::query()->with('property')->findOrFail($units->firstWhere('external_ref', 'U-'.$n.'-1')->id))),
    ];

    foreach ($saved as $target => $record) {
        expect($preview($target)['rows'])->toHaveCount(5);

        foreach ($preview($target)['rows'] as $i => $row) {
            expect($row['values'])->toBe(inOrganization($this->acme, fn (): array => $record($i + 1)));
        }
    }

    expect($preview('units')['columns'])->toBe(['Building', 'Unit', 'Type', 'Bedrooms', 'Rent per year', 'Service charge per year', 'Status']);

    // Values as the agency wrote them, kept the documented way.
    $company = $owners->firstWhere('external_ref', 'O-5');
    $updated = $owners->firstWhere('id', $known->id);
    $building = $buildings->firstWhere('external_ref', 'B-101');
    $unit = $units->firstWhere('external_ref', 'U-7-3');

    expect($company->type)->toBe(OwnerType::Company)
        ->and($company->company_name)->toBe('Owner5 Holdings Ltd')
        ->and($company->id_type)->toBe(OwnerIdType::Cac)
        ->and($company->id_value)->toBe('RC 100005')
        ->and($updated->external_ref)->toBe('O-3')
        ->and($updated->email)->toBe('owner3@example.com')
        ->and($updated->notes)->toBe('Kept')
        ->and($owners->firstWhere('external_ref', 'O-1')->id_value)->toBe('20000000001')
        ->and($building->name)->toBe('Building 101')
        ->and($building->slug)->toBe('building-101')
        ->and($building->owner_profile_id)->toBe($owners->firstWhere('external_ref', 'O-1')->id)
        ->and($building->property_type)->toBe(PropertyType::BlockOfFlats)
        ->and($building->amenities)->toBe(['Borehole', 'Security'])
        ->and($building->year_built)->toBe(2015)
        ->and($unit->property_id)->toBe($buildings->firstWhere('external_ref', 'B-7')->id)
        ->and($unit->unit_label)->toBe('Flat 3')
        ->and($unit->unit_type)->toBe(UnitType::Apartment)
        ->and($unit->base_rent_kobo->kobo)->toBe(100_300_000)
        ->and($unit->service_charge_kobo->kobo)->toBe(15_000_000)
        ->and($unit->status)->toBe(UnitStatus::Vacant)
        ->and(inOrganization($this->acme, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1500);

    // Each new unit counts towards the bill (P06 metering), as on the pages.
    Event::assertDispatchedTimes(UnitCreated::class, 1500);
    // Creates and updates are audited by the batch, not one entry per record.
    expect(AuditEntry::query()->where('log_name', 'imports')->where('event', 'import.committed')->sole()->properties['counts']['units']['create'])->toBe(1500)
        ->and(AuditEntry::query()->whereIn('log_name', ['owners', 'properties'])->count())->toBe(0);
});

it('creates no duplicates when the same workbook is imported again', function () {
    $file = portfolioFixture();
    $first = portfolioImport($this->acme, $this->owner, portfolioWorkbook($file));
    $before = portfolioTables();

    $again = portfolioDryRun($this->acme, $this->owner, portfolioWorkbook($file));

    expect($again->status)->toBe(ImportStatus::DryRunPassed)
        ->and($again->dry_run_report['counts'])->toEqual(['create' => 0, 'update' => 2000, 'restore' => 0, 'skip' => 0])
        ->and($again->dry_run_report['units'])->toBe(0)
        // No units added, so no bill warning; nothing differs, so no rent warning.
        ->and($again->dry_run_report['warnings'])->toBe(0);

    $again = portfolioCommit($this->acme, $this->owner, $again);

    expect($again->status)->toBe(ImportStatus::Committed)
        ->and($again->result_counts['targets'])->toEqual([
            'owners' => ['create' => 0, 'update' => 100, 'restore' => 0, 'skip' => 0],
            'properties' => ['create' => 0, 'update' => 200, 'restore' => 0, 'skip' => 0],
            'units' => ['create' => 0, 'update' => 1500, 'restore' => 0, 'skip' => 0],
            'tenants' => ['create' => 0, 'update' => 200, 'restore' => 0, 'skip' => 0],
        ]);

    // The same rows, ids and provenance (the first batch's), apart from timestamps.
    $withoutTimes = fn (array $tables): array => array_map(fn (array $rows): array => array_map(fn (object $row): array => array_diff_key((array) $row, ['updated_at' => true]), $rows), $tables);
    expect($withoutTimes(portfolioTables()))->toBe($withoutTimes($before))
        ->and(collect(portfolioTables())->flatten(1)->pluck('import_batch_id')->unique()->values()->all())->toBe([$first->id]);

    // The same file without the IDs still matches: owners by phone,
    // buildings by name (naming owners by name, or by phone for companies),
    // units by building and label, tenants by phone.
    $withoutIds = [];

    foreach ($file as $sheet => $rows) {
        $withoutIds[$sheet] = [$rows[0], ...array_map(fn (array $row): array => array_replace($row, [0 => '']), array_slice($rows, 1))];
    }

    foreach ($withoutIds['Buildings'] as $i => $row) {
        if ($i > 0) {
            $n = (int) substr($row[2], 2);
            $withoutIds['Buildings'][$i][2] = $n % 5 === 0 ? portfolioOwnerPhone($n, local: true) : "Owner{$n} Adeyemi";
        }
    }

    $third = portfolioImport($this->acme, $this->owner, portfolioWorkbook($withoutIds));

    expect($third->status)->toBe(ImportStatus::Committed)
        ->and($third->result_counts['targets']['owners'])->toEqual(['create' => 0, 'update' => 100, 'restore' => 0, 'skip' => 0])
        ->and($third->result_counts['targets']['properties'])->toEqual(['create' => 0, 'update' => 200, 'restore' => 0, 'skip' => 0])
        ->and($third->result_counts['targets']['units'])->toEqual(['create' => 0, 'update' => 1500, 'restore' => 0, 'skip' => 0])
        ->and(array_map('count', portfolioTables()))->toEqual(['owner_profiles' => 100, 'properties' => 200, 'units' => 1500, 'tenant_profiles' => 200])
        ->and(buildingsOf($this->acme)->firstWhere('name', 'Building 5')->owner_profile_id)->toBe(ownersOf($this->acme)->firstWhere('external_ref', 'O-5')->id);
});
