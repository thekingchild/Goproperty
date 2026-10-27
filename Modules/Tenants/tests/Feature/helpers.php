<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Models\ImportBatch;
use Modules\Tenants\Models\TenantProfile;
use Spatie\SimpleExcel\SimpleExcelWriter;

/*
 * Helpers for the Tenants module's tests (P02-06b). Imports go through the
 * real engine (Modules\Import): upload, dry run, commit (the queue is sync
 * in tests), rollback, as the import pages do. inOrganization() comes
 * from tests/Support/compliance.php.
 */

/** The Tenants sheet's header, as the template has it. */
const TENANT_HEADER = ['Your ID', 'First name', 'Last name', 'Phone', 'Email', 'Address', 'Occupation', 'Next of kin', 'Next of kin phone', 'Next of kin relationship', 'ID type', 'ID number'];

/** A real .xlsx workbook: sheet name => rows (the first row is the header). */
function tenantsWorkbook(array $sheets, string $name = 'tenants.xlsx'): UploadedFile
{
    $base = (string) tempnam(sys_get_temp_dir(), 'gp-tenants-test-');
    @unlink($base);
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();
    $first = true;

    foreach ($sheets as $sheet => $rows) {
        $first ? $writer->nameCurrentSheet($sheet) : $writer->addNewSheetAndMakeItCurrent($sheet);
        $first = false;

        foreach ($rows as $row) {
            $writer->addRow($row);
        }
    }

    $writer->close();

    return new UploadedFile($path, $name, null, null, true);
}

/** A CSV upload with these lines. */
function tenantsCsv(array $rows, string $name = 'tenants.csv'): UploadedFile
{
    $handle = fopen('php://temp', 'w+');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    rewind($handle);

    return UploadedFile::fake()->createWithContent($name, (string) stream_get_contents($handle));
}

/** The template's header and good tenant rows after it: phones 0803 000 0001, …; IDs T-1, …; NINs 1000000000n. */
function tenantRows(int $count, int $offset = 0): array
{
    $rows = [TENANT_HEADER];

    for ($i = 1; $i <= $count; $i++) {
        $n = $i + $offset;
        $rows[] = ['T-'.$n, "Tenant{$n}", 'Okafor', tenantPhone($n, local: true), "tenant{$n}@example.com", "{$n} Allen Avenue, Ikeja", 'Accountant', 'Chidi Okafor', '0805 987 6543', 'Brother', 'NIN', (string) (10_000_000_000 + $n)];
    }

    return $rows;
}

/** Tenant n's phone, as typed (0803 000 000n) or as kept (+234803000000n). */
function tenantPhone(int $n, bool $local = false): string
{
    $digits = '803'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);

    return $local ? '0'.substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6) : '+234'.$digits;
}

/** Upload, keep the suggested mapping, dry run. */
function tenantsDryRun(Organization $organization, User $by, UploadedFile $file): ImportBatch
{
    return inOrganization($organization, function () use ($by, $file): ImportBatch {
        $batches = app(ImportBatches::class);

        return $batches->dryRun($by, $batches->upload($by, $file));
    });
}

/** Commits a dry-run batch (the queue is sync) and returns it fresh. */
function tenantsCommit(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    return inOrganization($organization, function () use ($by, $batch): ImportBatch {
        app(ImportBatches::class)->commit($by, $batch);

        return ImportBatch::query()->findOrFail($batch->id);
    });
}

/** Dry run then commit. */
function tenantsImport(Organization $organization, User $by, UploadedFile $file): ImportBatch
{
    return tenantsCommit($organization, $by, tenantsDryRun($organization, $by, $file));
}

function tenantsRollback(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    return inOrganization($organization, fn (): ImportBatch => app(ImportBatches::class)->rollback($by, $batch));
}

/** Every tenant of the organization, archived ones too, oldest first. */
function tenantsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => TenantProfile::query()->withTrashed()->orderBy('id')->get());
}

function tenantIn(Organization $organization, array $attributes = []): TenantProfile
{
    return inOrganization($organization, fn (): TenantProfile => TenantProfile::factory()->create($attributes));
}

/** The dry run's problems as [row, header, letter, severity, message]. */
function tenantIssues(ImportBatch $batch): array
{
    return array_map(
        fn (array $issue): array => [$issue['row'], $issue['header'], $issue['letter'], $issue['severity'], $issue['message']],
        $batch->dry_run_report['sheets'][0]['issues'],
    );
}
