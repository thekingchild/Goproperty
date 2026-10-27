<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Tests\Fixtures\ContactsTarget;
use Modules\Import\Tests\Fixtures\ImportFixtures;
use Spatie\SimpleExcel\SimpleExcelWriter;

/*
 * Helpers for the Import module's tests (P02-06a). The engine is tested on
 * the fixture targets (Contacts, Notes), registered only in tests.
 */

/** A real .xlsx workbook: sheet name => rows (the first row is the header). */
function importWorkbook(array $sheets, string $name = 'contacts.xlsx'): UploadedFile
{
    $base = (string) tempnam(sys_get_temp_dir(), 'gp-import-test-');
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
function importCsv(array $rows, string $name = 'contacts.csv'): UploadedFile
{
    $handle = fopen('php://temp', 'w+');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    rewind($handle);

    return UploadedFile::fake()->createWithContent($name, (string) stream_get_contents($handle));
}

/** The Contacts header of the template, and good rows after it. */
function contactRows(int $count, int $offset = 0): array
{
    $rows = [['Your ID', 'Name', 'Phone', 'Joined on', 'Balance', 'Category']];

    for ($i = 1; $i <= $count; $i++) {
        $n = $i + $offset;
        $rows[] = ['C-'.$n, "Contact {$n}", '0803'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), '01/02/2025', '₦1,500.50', ContactsTarget::CATEGORIES[$n % 3]];
    }

    return $rows;
}

function inAcme(Organization $organization, Closure $callback): mixed
{
    return app(OrganizationContext::class)->run($organization, $callback);
}

/** Uploads through the service, as the page does. */
function uploadImport(Organization $organization, User $by, UploadedFile $file, ?string $target = null): ImportBatch
{
    return inAcme($organization, fn () => app(ImportBatches::class)->upload($by, $file, $target));
}

/** Upload, keep the suggested mapping, dry run. */
function dryRunImport(Organization $organization, User $by, UploadedFile $file): ImportBatch
{
    return inAcme($organization, function () use ($by, $file): ImportBatch {
        $batches = app(ImportBatches::class);
        $batch = $batches->upload($by, $file);

        return $batches->dryRun($by, $batch);
    });
}

function freshBatch(Organization $organization, ImportBatch $batch): ImportBatch
{
    return inAcme($organization, fn () => ImportBatch::query()->findOrFail($batch->id));
}

/** Every fixture contact of the organization, archived ones too, oldest first. */
function contactsOf(Organization $organization): Collection
{
    return inAcme($organization, fn () => ContactsTarget::table()->orderBy('id')->get());
}

function notesOf(Organization $organization): Collection
{
    return DB::table(ImportFixtures::NOTES)->where('organization_id', $organization->id)->orderBy('id')->get();
}
