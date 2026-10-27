<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Models\ImportBatch;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../../tests/Feature/Exports/helpers.php';

/*
 * P03-01a sub-AC "the list exports" (P02-07, AGENT.md § 5): the tenants
 * list exports in Excel and CSV exactly the rows the page shows with its
 * filters, never another organization's, with the ID number masked (never
 * the number or its ciphertext), audited, and back through the import as
 * updates. Refused to impersonating staff (TenantRolesTest).
 * ListExportCoverageTest walks the sidebar, so it covers this list too.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);

    $this->ngozi = tenantIn($this->acme, [
        'first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348031234567', 'email' => 'ngozi@eze.ng',
        'emergency_contact' => ['name' => 'Chidi Eze', 'phone' => '+2348059876543', 'relationship' => 'Brother'],
        'id_type' => TenantIdType::Nin, 'id_value' => '12345678901', 'kyc_status' => KycStatus::Verified,
    ]);
    $this->kunle = tenantIn($this->acme, ['first_name' => 'Kunle', 'last_name' => 'Adeyemi', 'phone' => '+2348120001111', 'id_type' => TenantIdType::Passport, 'id_value' => 'A07654321']);
    $this->gone = tenantIn($this->acme, ['first_name' => 'Old', 'last_name' => 'Tenant', 'phone' => '+2348090000001']);
    inOrganization($this->acme, fn () => $this->gone->delete());
    tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348031234567']);
});

afterEach(fn () => exportsFreshRequest());

it('exports the tenants the page shows, in Excel and CSV, with the ID masked and never the number', function () {
    $stored = DB::table('tenant_profiles')->whereIn('id', [$this->ngozi->id, $this->kunle->id])->pluck('id_value')->all();

    foreach (['csv', 'xlsx'] as $format) {
        $download = openDownload($this->owner, pressExport($this->owner, '/tenants', 'tenants', $format))->assertOk();
        $content = $download->streamedContent();
        $rows = fileRows($content, $format);

        expect($rows[0])->toBe(['Your ID', 'First name', 'Last name', 'Phone', 'Email', 'Address', 'Occupation', 'Next of kin', 'Next of kin phone', 'Next of kin relationship', 'ID (masked)', 'KYC status', 'Portal access', 'Added on', 'Archived on'])
            ->and(exportColumn($rows, 'Last name'))->toBe(['Adeyemi', 'Eze'])
            ->and(exportColumn($rows, 'Phone'))->toBe(['+2348120001111', '+2348031234567'])
            ->and(exportColumn($rows, 'ID (masked)'))->toBe(['International passport ···4321', 'NIN ···8901'])
            ->and(exportColumn($rows, 'KYC status'))->toBe(['Not verified', 'Verified'])
            ->and(exportColumn($rows, 'Next of kin'))->toBe(['', 'Chidi Eze'])
            ->and(exportColumn($rows, 'Portal access'))->toBe(['No', 'No']);

        $plain = $format === 'csv' ? $content : implode("\n", array_map(fn (array $row): string => implode(',', $row), $rows)).xlsxSheetXml($content);

        expect($plain)->not->toContain('12345678901')
            ->not->toContain('A07654321')
            ->not->toContain('7654321')
            ->not->toContain($stored[0])
            ->not->toContain($stored[1])
            ->not->toContain('Foreign')
            ->not->toContain('Old');
    }
});

it('exports with the page\'s current filters: search, KYC status, portal access and archived', function () {
    inOrganization($this->acme, fn () => $this->kunle->forceFill(['user_id' => User::factory()->create()->id])->save());

    expect(exportColumn(exportRows($this->owner, '/tenants?q=eze', 'tenants'), 'First name'))->toBe(['Ngozi'])
        ->and(exportColumn(exportRows($this->owner, '/tenants?kyc=verified', 'tenants'), 'First name'))->toBe(['Ngozi'])
        ->and(exportColumn(exportRows($this->owner, '/tenants?portal=yes', 'tenants'), 'First name'))->toBe(['Kunle'])
        ->and(exportColumn(exportRows($this->owner, '/tenants?archived=1', 'tenants'), 'First name'))->toBe(['Old'])
        // Filters typed on the page, not in the URL.
        ->and(exportColumn(exportRows($this->owner, '/tenants', 'tenants', 'csv', ['search' => '0812 000']), 'First name'))->toBe(['Kunle'])
        ->and(exportColumn(exportRows($this->owner, '/tenants', 'tenants', 'csv', ['portal' => 'no']), 'First name'))->toBe(['Ngozi']);

    // The other organization exports only its own tenant.
    $otherOwner = joinAs(User::factory()->create(), $this->other);
    expect(exportColumn(exportRows($otherOwner, '/tenants', 'tenants', 'csv', [], 'other-realty'), 'First name'))->toBe(['Foreign']);
});

it('audits every tenants export: who, the format, the filters and the row count', function () {
    exportRows($this->owner, '/tenants?kyc=verified', 'tenants', 'xlsx');

    $entry = AuditEntry::query()->where('log_name', 'exports')->sole();
    expect($entry->event)->toBe('list.exported')
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and($entry->getProperty('list'))->toBe('tenants')
        ->and($entry->getProperty('format'))->toBe('xlsx')
        ->and($entry->getProperty('filters'))->toEqual(['kyc' => 'verified'])
        ->and($entry->getProperty('rows'))->toBe(1);
});

it('imports its own tenants export again, as updates of the same tenants', function () {
    $download = openDownload($this->owner, pressExport($this->owner, '/tenants', 'tenants'))->assertOk();
    $file = UploadedFile::fake()->createWithContent('tenants.csv', $download->streamedContent());

    $batch = inOrganization($this->acme, function () use ($file): ImportBatch {
        $batches = app(ImportBatches::class);

        return $batches->dryRun($this->owner, $batches->upload($this->owner, $file, 'tenants'));
    });

    $issues = collect($batch->dry_run_report['sheets'])->flatMap(fn (array $sheet): array => $sheet['issues'])->where('severity', 'error');
    expect($issues->all())->toBe([])
        ->and($batch->dry_run_report['counts'])->toMatchArray(['create' => 0, 'update' => 2, 'restore' => 0]);
});
