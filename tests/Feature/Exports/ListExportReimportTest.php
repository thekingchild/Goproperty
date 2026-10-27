<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Models\ImportBatch;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\UnitStatus;

require_once __DIR__.'/helpers.php';

/*
 * P02-07 with DATA_IMPORT.md § 6 ("agencies are never locked in"): the
 * owners, buildings and units exports use the import's headers, so an
 * exported file goes back through the import as it is, matching every row
 * to the record it came from (updates, no new records, no errors).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $owner = ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348031234567', 'id_type' => OwnerIdType::Nin, 'id_value' => '12345678901']);
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'owner_profile_id' => $owner->id]);
    unitIn($this->acme, $palm, ['unit_label' => 'Flat 1', 'base_rent_kobo' => Money::naira(1_500_000), 'status' => UnitStatus::Vacant]);
    unitIn($this->acme, $palm, ['unit_label' => 'Flat 2', 'base_rent_kobo' => Money::naira(1_200_000), 'status' => UnitStatus::UnderMaintenance]);
});

afterEach(fn () => exportsFreshRequest());

it('imports its own owners, buildings and units exports again, as updates of the same records', function (string $list, string $path, string $target, int $rows) {
    $download = openDownload($this->owner, pressExport($this->owner, $path, $list))->assertOk();
    $file = UploadedFile::fake()->createWithContent("{$list}.csv", $download->streamedContent());

    $batch = inOrganization($this->acme, function () use ($file, $target): ImportBatch {
        $batches = app(ImportBatches::class);

        return $batches->dryRun($this->owner, $batches->upload($this->owner, $file, $target));
    });

    $issues = collect($batch->dry_run_report['sheets'])->flatMap(fn (array $sheet): array => $sheet['issues'])->where('severity', 'error');
    expect($issues->all())->toBe([])
        ->and($batch->dry_run_report['counts'])->toMatchArray(['create' => 0, 'update' => $rows, 'restore' => 0]);
})->with([
    'owners' => ['owners', '/owners', 'owners', 1],
    'buildings' => ['buildings', '/buildings', 'properties', 1],
    'units' => ['units', '/units', 'units', 2],
]);
