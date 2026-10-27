<?php

declare(strict_types=1);

use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportFileType;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Models\ImportColumnMapping;
use Modules\Import\Reading\SpreadsheetFile;
use Modules\Import\Tests\Fixtures\ImportFixtures;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

require_once __DIR__.'/helpers.php';

/*
 * Maps read back from json columns are compared with toEqual: MySQL
 * reorders object keys (MariaDB keeps them).
 *
 * P02-06a: upload, mapping suggestions and the dry run (DATA_IMPORT.md
 * § 2.1–2.3, § 4) on the fixture targets. The dry run validates and
 * simulates every row and saves nothing.
 */

beforeEach(function () {
    Storage::fake('media');
    ImportFixtures::install();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => ImportFixtures::drop());

it('stores the upload in the media library and suggests the mapping from sheet names and headers', function () {
    $batch = uploadImport($this->acme, $this->owner, importWorkbook([
        'Notes' => [['Contact phone', 'Note'], ['08030000001', 'Hi']],
        'contacts' => contactRows(2),
        'Other' => [['Colour', 'Size'], ['Red', 'L']],
    ], 'Agency data.xlsx'));

    $media = inAcme($this->acme, fn () => Media::query()->findOrFail($batch->media_id));
    $sheets = collect($batch->sheets())->keyBy('name');

    expect($batch->status)->toBe(ImportStatus::Uploaded)
        ->and($batch->file_type)->toBe(ImportFileType::Xlsx)
        ->and($batch->file_name)->toBe('Agency data.xlsx')
        ->and($batch->kind)->toBe('workbook')
        ->and($batch->created_by_user_id)->toBe($this->owner->id)
        ->and($media->collection_name)->toBe('imports')
        ->and($media->model_type)->toBe(ImportBatch::class)
        ->and($media->model_id)->toBe($batch->id)
        ->and($media->mime_type)->toBe(ImportFileType::XLSX_MIME)
        ->and($media->file_name)->toEndWith('.xlsx')
        ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue()
        ->and($sheets['contacts']->target)->toBe('contacts')
        ->and($sheets['contacts']->columns)->toEqual(['external_ref' => 0, 'name' => 1, 'phone' => 2, 'joined_on' => 3, 'balance' => 4, 'category' => 5])
        ->and($sheets['Notes']->target)->toBe('notes')
        ->and($sheets['Other']->target)->toBeNull();
});

it('suggests the target of a CSV from its headers and maps agencies\' own header names through aliases', function () {
    $batch = uploadImport($this->acme, $this->owner, importCsv([['Full name', 'Mobile', 'Amount owed', 'Remarks'], ['Ada Obi', '08031234567', '5,000', 'x']]));
    $sheet = $batch->sheets()[0];

    expect($batch->file_type)->toBe(ImportFileType::Csv)
        ->and($batch->kind)->toBe('contacts')
        ->and($sheet->target)->toBe('contacts')
        ->and($sheet->columns)->toEqual(['name' => 0, 'phone' => 1, 'balance' => 2]);
});

it('remembers a saved mapping for the organization and uses it for the next file', function () {
    $file = fn () => importCsv([['Customer', 'Cell', 'Debt'], ['Ada Obi', '08031234567', '5,000']]);
    $batch = uploadImport($this->acme, $this->owner, $file(), 'contacts');
    expect($batch->sheets()[0]->columns)->toBe([]);

    inAcme($this->acme, fn () => app(ImportBatches::class)->map($this->owner, $batch, [0 => ['target' => 'contacts', 'columns' => ['name' => '0', 'phone' => '1', 'balance' => '2']]], 'My bank sheet'));

    $saved = inAcme($this->acme, fn () => ImportColumnMapping::query()->sole());
    expect($saved->kind)->toBe('contacts')
        ->and($saved->name)->toBe('My bank sheet')
        ->and($saved->mapping)->toEqual(['customer' => 'name', 'cell' => 'phone', 'debt' => 'balance']);

    $next = uploadImport($this->acme, $this->owner, $file(), 'contacts');
    expect($next->sheets()[0]->columns)->toEqual(['name' => 0, 'phone' => 1, 'balance' => 2]);

    // Another organization does not see it.
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    expect(uploadImport($other, $otherOwner, $file(), 'contacts')->sheets()[0]->columns)->toBe([]);
});

it('writes nothing during a dry run', function () {
    inAcme($this->acme, fn () => DB::table(ImportFixtures::CONTACTS)->insert(['organization_id' => $this->acme->id, 'name' => 'Old', 'phone' => '08030000001', 'external_ref' => 'C-1', 'created_at' => now(), 'updated_at' => now()]));
    $batch = uploadImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(30), 'Notes' => [['Contact phone', 'Note'], ['08030000002', 'Prefers calls']]]));

    $before = [contactsOf($this->acme)->toArray(), notesOf($this->acme)->toArray()];
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1
            && ! preg_match('/`(import_batches|activity_log|audit_chain_head)`/', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $batch = inAcme($this->acme, fn () => app(ImportBatches::class)->dryRun($this->owner, $batch));

    expect($writes)->toBe([])
        ->and([contactsOf($this->acme)->toArray(), notesOf($this->acme)->toArray()])->toEqual($before)
        ->and($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and($batch->dry_run_report['rows'])->toBe(31)
        ->and($batch->dry_run_report['counts'])->toEqual(['create' => 30, 'update' => 1, 'restore' => 0, 'skip' => 0])
        ->and($batch->rows_total)->toBe(31);
});

it('reports every bad row with its exact row, column and message, and blocks the commit', function () {
    $rows = contactRows(4);
    $rows[2][2] = '';                 // row 3: no phone
    $rows[3][3] = '31/02/2025';       // row 4: no such date
    array_splice($rows, 4, 0, [['', '', '', '', '', '']]); // row 5 blank: ignored, numbering kept
    $rows[5][4] = 'lots';             // row 6: amount
    $rows[5][5] = 'Platinum';         //        category
    $rows[] = ['C-9', 'Twin', $rows[1][2], '', '', ''];   // row 7: phone of row 2

    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => $rows]));
    $sheet = $batch->dry_run_report['sheets'][0];
    $issues = array_map(fn (array $i): array => [$i['row'], $i['header'], $i['letter'], $i['message']], $sheet['issues']);

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and($sheet['rows'])->toBe(5)
        ->and($sheet['errors'])->toBe(5)
        ->and($issues)->toBe([
            [3, 'Phone', 'C', 'Enter a phone number.'],
            [4, 'Joined on', 'D', '"31/02/2025" is not a real date. Enter it as DD/MM/YYYY.'],
            [6, 'Balance', 'E', 'Enter an amount in naira, such as 150,000 or ₦150,000.50.'],
            [6, 'Category', 'F', 'Choose one of: Gold, Silver, Bronze.'],
            [7, 'Phone', 'C', 'This phone number is already on row 2.'],
        ])
        ->and($sheet['counts']['create'])->toBe(1);

    expect(fn () => inAcme($this->acme, fn () => app(ImportBatches::class)->commit($this->owner, $batch)))
        ->toThrow(ImportRefused::class);
});

it('lets a later sheet refer to records an earlier sheet creates, and refuses unknown references', function () {
    $batch = dryRunImport($this->acme, $this->owner, importWorkbook([
        'Notes' => [['Contact phone', 'Note'], ['08030000001', 'Known from the Contacts sheet'], ['08099999999', 'Nobody']],
        'Contacts' => contactRows(1),
    ]));

    $report = collect($batch->dry_run_report['sheets'])->keyBy('target');

    expect(array_column($batch->dry_run_report['sheets'], 'target'))->toBe(['contacts', 'notes'])
        ->and($report['notes']['errors'])->toBe(1)
        ->and($report['notes']['issues'][0]['row'])->toBe(3)
        ->and($report['notes']['issues'][0]['header'])->toBe('Contact phone');
});

it('reports a required column that is not mapped as a sheet error, and warnings without blocking', function () {
    $batch = dryRunImport($this->acme, $this->owner, importCsv([['Name', 'Category'], ['Ada', 'Gold']], 'x.csv'));
    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and($batch->dry_run_report['sheets'][0]['issues'][0]['message'])->toBe('Choose the file column for Phone: it is required.');

    $rows = contactRows(1);
    $rows[1][3] = now()->addYear()->format('d/m/Y');
    $warned = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => $rows]));

    expect($warned->status)->toBe(ImportStatus::DryRunPassed)
        ->and($warned->dry_run_report['warnings'])->toBe(1)
        ->and($warned->dry_run_report['sheets'][0]['issues'][0]['severity'])->toBe('warning');
});

it('refuses a sheet over 10,000 rows and asks for it to be split', function () {
    config(['import.max_rows_per_sheet' => 3]);

    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => contactRows(5)]));

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(collect($batch->dry_run_report['sheets'][0]['issues'])->last()['message'])->toBe('This sheet has more than 3 rows. Split it into files of 3 rows or fewer.');
});

it('reads dates, amounts and numbers the way agencies type them', function () {
    $rows = [['Name', 'Phone', 'Joined on', 'Balance'],
        // A real Excel date cell: a serial number with a date format.
        new Row([Cell::fromValue('A'), Cell::fromValue(8031234567), Cell::fromValue(new DateTimeImmutable('2025-03-04'), (new Style)->setFormat('dd/mm/yyyy')), Cell::fromValue(150000)]),
        ['B', '0803 123 4568', '4/3/2025', 'N150,000.5'],
        ['C', '+234 803 123 4569', '2025-03-04', '1500.25'],
    ];
    $batch = dryRunImport($this->acme, $this->owner, importWorkbook(['Contacts' => $rows]));

    expect($batch->dry_run_report['sheets'][0]['issues'])->toBe([])
        ->and($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(array_column($batch->dry_run_report['sheets'][0]['preview']['rows'], 'values'))->toBe([
            ['A', '8031234567', '₦150,000'],
            ['B', '08031234568', '₦150,000.50'],
            ['C', '2348031234569', '₦1,500.25'],
        ]);
});

it('streams a large workbook without holding it in memory', function () {
    $file = importWorkbook(['Contacts' => contactRows(5000)]);
    $reader = SpreadsheetFile::open($file->getRealPath(), ImportFileType::Xlsx);

    memory_reset_peak_usage();
    $start = memory_get_usage();
    $rows = 0;

    foreach ($reader->rows(0, 2) as $cells) {
        $rows++;
    }

    expect($rows)->toBe(5000)
        ->and(memory_get_peak_usage() - $start)->toBeLessThan(16 * 1024 * 1024)
        ->and(SheetMapping::letter(0))->toBe('A')
        ->and(SheetMapping::letter(27))->toBe('AB');
});
