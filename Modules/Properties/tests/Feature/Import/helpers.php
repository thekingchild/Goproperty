<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Models\ImportBatch;
use Spatie\SimpleExcel\SimpleExcelWriter;

require_once __DIR__.'/../Units/helpers.php';

/*
 * Helpers for the Owners, Buildings and Units import sheets (P02-06c).
 * Imports go through the real engine (Modules\Import): upload, dry run,
 * commit (the queue is sync in tests unless a test says otherwise),
 * rollback, as the import pages do. inOrganization() comes from
 * tests/Support/compliance.php.
 */

/** The template's headers. */
const PORTFOLIO_OWNERS = ['Your ID', 'Name', 'Type', 'Phone', 'Email', 'Address', 'State', 'LGA', 'ID type', 'ID number', 'Notes'];

const PORTFOLIO_BUILDINGS = ['Your ID', 'Building name', 'Owner', 'Type', 'Address', 'State', 'LGA', 'Landmark', 'Amenities', 'Year built', 'Description'];

const PORTFOLIO_UNITS = ['Your ID', 'Building', 'Unit', 'Type', 'Bedrooms', 'Bathrooms', 'Size (sq m)', 'Floor', 'Rent per year', 'Service charge per year', 'Status', 'Description'];

const PORTFOLIO_TENANTS = ['Your ID', 'First name', 'Last name', 'Phone', 'Email', 'Address', 'Occupation', 'Next of kin', 'Next of kin phone', 'Next of kin relationship', 'ID type', 'ID number'];

/** A real .xlsx workbook: sheet name => rows (the first row is the header). */
function portfolioWorkbook(array $sheets, string $name = 'portfolio.xlsx'): UploadedFile
{
    $base = (string) tempnam(sys_get_temp_dir(), 'gp-portfolio-test-');
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

/** Owner n's phone, as typed (0802 000 000n) or as kept (+234802000000n). */
function portfolioOwnerPhone(int $n, bool $local = false): string
{
    $digits = '802'.str_pad((string) $n, 7, '0', STR_PAD_LEFT);

    return $local ? '0'.substr($digits, 0, 3).' '.substr($digits, 3, 3).' '.substr($digits, 6) : '+234'.$digits;
}

/** Good owner rows after the header: IDs O-1, …; "Owner{n} Adeyemi"; every fifth a company. */
function portfolioOwnerRows(int $count, int $offset = 0): array
{
    $rows = [PORTFOLIO_OWNERS];

    for ($i = 1; $i <= $count; $i++) {
        $n = $i + $offset;
        $company = $n % 5 === 0;
        $rows[] = [
            'O-'.$n,
            $company ? "Owner{$n} Holdings Ltd" : "Owner{$n} Adeyemi",
            $company ? 'Company' : 'Person',
            portfolioOwnerPhone($n, local: true),
            "owner{$n}@example.com",
            "{$n} Bourdillon Road, Ikoyi",
            'Lagos',
            'Eti-Osa',
            $company ? 'CAC number' : 'NIN',
            $company ? 'RC '.(100000 + $n) : (string) (20_000_000_000 + $n),
            '',
        ];
    }

    return $rows;
}

/** Good building rows: IDs B-1, …; "Building {n}", owned by owner ((n-1) % owners) + 1 by Your ID. */
function portfolioBuildingRows(int $count, int $owners, int $offset = 0): array
{
    $rows = [PORTFOLIO_BUILDINGS];

    for ($i = 1; $i <= $count; $i++) {
        $n = $i + $offset;
        $rows[] = ['B-'.$n, "Building {$n}", $owners > 0 ? 'O-'.((($n - 1) % $owners) + 1) : '', 'Block of flats', "{$n} Palm Avenue, Lekki", 'Lagos', 'Eti-Osa', 'Near the toll gate', 'Borehole, Security', '2015', ''];
    }

    return $rows;
}

/** Good unit rows: `$perBuilding` flats in each of buildings 1…`$buildings` (by name), IDs U-<building>-<flat>, rent ₦1,000,000 + ₦1,000 × flat. */
function portfolioUnitRows(int $buildings, int $perBuilding): array
{
    $rows = [PORTFOLIO_UNITS];

    for ($b = 1; $b <= $buildings; $b++) {
        for ($f = 1; $f <= $perBuilding; $f++) {
            $rows[] = ["U-{$b}-{$f}", "Building {$b}", "Flat {$f}", 'Flat', '2', '2', '', '1st', '₦'.number_format(1_000_000 + 1_000 * $f), '150,000', '', ''];
        }
    }

    return $rows;
}

/** Good tenant rows: phones 0803 100 000n. */
function portfolioTenantRows(int $count): array
{
    $rows = [PORTFOLIO_TENANTS];

    for ($n = 1; $n <= $count; $n++) {
        $rows[] = ['T-'.$n, "Tenant{$n}", 'Okafor', '0803 1'.substr(str_pad((string) $n, 6, '0', STR_PAD_LEFT), 0, 2).' '.substr(str_pad((string) $n, 6, '0', STR_PAD_LEFT), 2), '', '', '', '', '', '', '', ''];
    }

    return $rows;
}

/**
 * The 2,000-row fixture workbook (DATA_IMPORT.md § 1 rows 1–4): 100
 * owners, 200 buildings, 1,500 units (7 or 8 a building), 200 tenants.
 */
function portfolioFixture(): array
{
    $units = [PORTFOLIO_UNITS];

    for ($n = 1; $n <= 1500; $n++) {
        $building = (($n - 1) % 200) + 1;
        $flat = intdiv($n - 1, 200) + 1;
        $units[] = ["U-{$building}-{$flat}", "Building {$building}", "Flat {$flat}", 'Flat', '2', '2', '', '1st', '₦'.number_format(1_000_000 + 1_000 * $flat), '150,000', '', ''];
    }

    return [
        'Owners' => portfolioOwnerRows(100),
        'Buildings' => portfolioBuildingRows(200, 100),
        'Units' => $units,
        'Tenants' => portfolioTenantRows(200),
    ];
}

/** Upload, keep the suggested mapping, dry run. */
function portfolioDryRun(Organization $organization, User $by, UploadedFile $file): ImportBatch
{
    return inOrganization($organization, function () use ($by, $file): ImportBatch {
        $batches = app(ImportBatches::class);

        return $batches->dryRun($by, $batches->upload($by, $file));
    });
}

/** Commits a dry-run batch and returns it fresh. */
function portfolioCommit(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    return inOrganization($organization, function () use ($by, $batch): ImportBatch {
        app(ImportBatches::class)->commit($by, $batch);

        return ImportBatch::query()->findOrFail($batch->id);
    });
}

/** Dry run then commit. */
function portfolioImport(Organization $organization, User $by, UploadedFile $file): ImportBatch
{
    return portfolioCommit($organization, $by, portfolioDryRun($organization, $by, $file));
}

function portfolioRollback(Organization $organization, User $by, ImportBatch $batch): ImportBatch
{
    return inOrganization($organization, fn (): ImportBatch => app(ImportBatches::class)->rollback($by, $batch));
}

/** The dry run's problems of the sheet holding `$target`, as [row, header, letter, severity, message]. */
function portfolioIssues(ImportBatch $batch, string $target): array
{
    $sheet = collect($batch->dry_run_report['sheets'])->firstWhere('target', $target);

    return array_map(
        fn (array $issue): array => [$issue['row'], $issue['header'], $issue['letter'], $issue['severity'], $issue['message']],
        $sheet['issues'] ?? [],
    );
}

/** The dry run's counts of the sheet holding `$target`. */
function portfolioCounts(ImportBatch $batch, string $target): array
{
    return collect($batch->dry_run_report['sheets'])->firstWhere('target', $target)['counts'] ?? [];
}

/** The rows of the organization's portfolio tables, read straight from the database. */
function portfolioTables(): array
{
    return collect(['owner_profiles', 'properties', 'units', 'tenant_profiles'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()])
        ->all();
}
