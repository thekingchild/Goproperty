<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Jobs\PurgeImportUploads;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Reading\SpreadsheetFile;
use Modules\Import\Tests\Fixtures\ImportFixtures;

require_once __DIR__.'/helpers.php';

/*
 * P02-06a: the spreadsheets the import offers (the template, the error
 * workbook) and the 30-day purge of uploaded files (DATA_IMPORT.md § 2.1,
 * § 2.3, § 4).
 */

beforeEach(function () {
    Storage::fake('media');
    ImportFixtures::install();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    ImportFixtures::drop();
    app(OrganizationContext::class)->clear();
});

/** The downloaded workbook saved to a file. */
function downloadedWorkbook(TestResponse $response): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-download-');
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return $path;
}

/** @return list<list<mixed>> every row of a sheet */
function sheetRows(string $path, int $sheet): array
{
    return array_values(iterator_to_array(SpreadsheetFile::open($path, ImportFileType::Xlsx)->rows($sheet), false));
}

it('offers a template with instructions, a sheet per target and dropdowns for fixed values', function () {
    $response = $this->actingAs($this->owner)->get('/o/acme-homes/imports/template')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = downloadedWorkbook($response);
    $file = SpreadsheetFile::open($path, ImportFileType::Xlsx);

    // The Lists sheet is hidden, so it is not offered for import. Real
    // targets registered by modules (Owners, Buildings, Units: P02-06c;
    // Tenants: P02-06b) come in commit order with the fixture targets.
    expect(array_column($file->sheets(), 'name'))->toBe(['Instructions', 'Contacts', 'Owners', 'Notes', 'Buildings', 'Units', 'Tenants'])
        ->and(sheetRows($path, 1)[0])->toBe(['Your ID', 'Name', 'Phone', 'Joined on', 'Balance', 'Category'])
        ->and(sheetRows($path, 1)[1])->toBe(['C-001', 'Ada Obi', '08031234567', '31/12/2025', '₦150,000', 'Gold'])
        ->and(sheetRows($path, 3)[0])->toBe(['Contact phone', 'Note'])
        ->and(collect(sheetRows($path, 0))->flatten()->implode(' '))->toContain('nothing is saved until you commit')->toContain('Gold, Silver, Bronze');

    $zip = new ZipArchive;
    $zip->open($path);
    $contacts = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
    $zip->close();

    expect($contacts)->toContain('<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="F2:F10001"><formula1>Lists!$A$1:$A$3</formula1></dataValidation>')
        // The first list column is padded to the longest list (the 37 states).
        ->and(array_column(sheetRows($path, 7), 0))->toBe(['Gold', 'Silver', 'Bronze', ...array_fill(0, 34, '')]);

    // A filled-in template maps itself.
    $batch = uploadImport($this->acme, $this->owner, new UploadedFile($path, 'template.xlsx', null, null, true));
    expect(collect($batch->sheets())->pluck('target')->all())->toBe([null, 'contacts', 'owners', 'notes', 'properties', 'units', 'tenants']);
});

it('downloads the problems as a workbook: every row with its problems next to it, and a list of all of them', function () {
    $rows = contactRows(3);
    $rows[2][2] = '';
    $rows[3][4] = 'lots';
    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => $rows, 'Notes' => [['Contact phone', 'Note'], ['0809', 'Nobody']]]));

    $path = downloadedWorkbook($this->actingAs($this->owner)->get("/o/acme-homes/imports/{$batch->id}/problems")->assertOk());
    $contacts = sheetRows($path, 0);
    $summary = sheetRows($path, 2);

    expect(array_column(SpreadsheetFile::open($path, ImportFileType::Xlsx)->sheets(), 'name'))->toBe(['Contacts', 'Notes', 'All problems'])
        ->and($contacts[0])->toBe(['Your ID', 'Name', 'Phone', 'Joined on', 'Balance', 'Category', 'Problems', 'Row in your file'])
        ->and($contacts[1][6])->toBe('')
        ->and($contacts[1][7])->toBe(2)
        ->and($contacts[2][6])->toBe('Phone (C): Enter a phone number.')
        ->and($contacts[2][7])->toBe(3)
        ->and($contacts[3][6])->toBe('Balance (E): Enter an amount in naira, such as 150,000 or ₦150,000.50.')
        ->and($summary)->toBe([
            ['Sheet', 'Row', 'Column', 'Kind', 'Problem'],
            ['Contacts', 3, 'Phone (C)', 'Error', 'Enter a phone number.'],
            ['Contacts', 4, 'Balance (E)', 'Error', 'Enter an amount in naira, such as 150,000 or ₦150,000.50.'],
            ['Notes', 2, 'Contact phone (A)', 'Error', 'No contact has this phone number, here or in the Contacts sheet.'],
        ]);
});

it('deletes uploaded files after 30 days, keeping the batch and its report', function () {
    $old = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(2)]));
    $media = inAcme($this->acme, fn () => Media::query()->findOrFail($old->media_id));
    $file = $media->getPathRelativeToRoot();

    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $theirs = uploadImport($other, joinAs(User::factory()->create(), $other), importWorkbook(['Contacts' => contactRows(1)]));
    $this->travel(29)->days();
    $recent = uploadImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(1)]));
    $this->travel(2)->days();

    // The scheduled event, as cron runs it (the queue runs inline in tests).
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === 'import-uploads-purge');
    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('40 3 * * *');
    $event->run(app());

    $old = freshBatch($this->acme, $old);
    expect($old->media_id)->toBeNull()
        ->and($old->file_purged_at)->not->toBeNull()
        ->and($old->status)->toBe(ImportStatus::DryRunPassed)
        ->and($old->dry_run_report['rows'])->toBe(2)
        ->and(Storage::disk('media')->exists($file))->toBeFalse()
        ->and(inAcme($this->acme, fn () => Media::query()->whereKey($media->id)->exists()))->toBeFalse()
        ->and(freshBatch($this->acme, $recent)->media_id)->not->toBeNull()
        ->and(AuditEntry::query()->where('log_name', 'imports')->where('event', 'import.file_purged')->count())->toBe(2)
        ->and(inAcme($other, fn () => ImportBatch::query()->findOrFail($theirs->id))->media_id)->toBeNull();

    // Without its file the batch cannot go further, and says so.
    expect(fn () => inAcme($this->acme, fn () => app(ImportBatches::class)->commit($this->owner, $old)))->toThrow(ImportRefused::class, 'no longer kept');
    $this->actingAs($this->owner)->get("/o/acme-homes/imports/{$old->id}/problems")->assertNotFound();

    // Running it again changes nothing.
    (new PurgeImportUploads)->handle(app(OrganizationContext::class), app(ImportBatches::class));
    expect(AuditEntry::query()->where('event', 'import.file_purged')->count())->toBe(2);
});

it('keeps the file of a batch still committing', function () {
    $committing = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(1)]));
    inAcme($this->acme, fn () => ImportBatch::query()->whereKey($committing->id)->update(['status' => ImportStatus::Committing->value]));
    $this->travel(40)->days();

    (new PurgeImportUploads)->handle(app(OrganizationContext::class), app(ImportBatches::class));
    expect(freshBatch($this->acme, $committing)->media_id)->not->toBeNull();

    inAcme($this->acme, fn () => ImportBatch::query()->whereKey($committing->id)->update(['status' => ImportStatus::Committed->value]));
    (new PurgeImportUploads)->handle(app(OrganizationContext::class), app(ImportBatches::class));
    expect(freshBatch($this->acme, $committing)->media_id)->toBeNull();
});
