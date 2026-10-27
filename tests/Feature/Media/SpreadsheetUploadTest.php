<?php

declare(strict_types=1);

use App\Media\CollectionRules;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

require_once __DIR__.'/../../Support/media.php';

/*
 * P02-06a: spreadsheets for imports (collection `imports`, DATA_IMPORT.md
 * § 4). finfo reports an .xlsx as a zip and a .csv as plain text, so the
 * validator checks the content too: an .xlsx must be a zip holding an
 * Excel workbook without macros, a .csv plain text with no NUL bytes and
 * no markup or script. SVG and scripts stay refused, whatever their name.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->validator = app(UploadValidator::class);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

function spreadsheetUpload(string $name = 'owners.xlsx'): UploadedFile
{
    $base = (string) tempnam(sys_get_temp_dir(), 'gp-xlsx-test-');
    @unlink($base);
    $writer = SimpleExcelWriter::create($base.'.xlsx', 'xlsx');
    $writer->addRow(['Name' => 'Ada Obi', 'Phone' => '08031234567']);
    $writer->close();

    return new UploadedFile($base.'.xlsx', $name, null, null, true);
}

/** A zip with these entries, as an upload. */
function zipUpload(string $name, array $entries): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'gp-zip-test-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $content) {
        $zip->addFromString($entry, $content);
    }

    $zip->close();

    return new UploadedFile($path, $name, null, null, true);
}

function expectSpreadsheetRefused(Organization $organization, UploadedFile $file, string $message, string $collection = 'imports'): void
{
    expect(fn () => uploadMedia($organization, $file, $collection))->toThrow(UploadRefused::class, $message)
        ->and(mediaCount($organization))->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
}

it('accepts an Excel workbook and a CSV file, typed and named from their content', function () {
    $workbook = uploadMedia($this->acme, spreadsheetUpload('Owners list.XLSX'), 'imports');
    $csv = uploadMedia($this->acme, UploadedFile::fake()->createWithContent('tenants.txt', "Name,Phone\nAda Obi,08031234567\n"), 'imports');
    // Excel on Windows saves CSV as Windows-1252: still text.
    $legacy = uploadMedia($this->acme, UploadedFile::fake()->createWithContent('legacy.csv', "Name;Amount\nAd\xE9 Ob\xED;\x80100\n"), 'imports');

    expect($workbook->mime_type)->toBe(CollectionRules::XLSX)
        ->and($workbook->file_name)->toEndWith('.xlsx')
        ->and($workbook->name)->toBe('Owners list')
        ->and($csv->mime_type)->toBe('text/csv')
        ->and($csv->file_name)->toEndWith('.csv')
        ->and($legacy->mime_type)->toBe('text/csv');
});

it('refuses a renamed script, whatever spreadsheet name it has', function (string $name, string $content) {
    expectSpreadsheetRefused($this->acme, UploadedFile::fake()->createWithContent($name, $content), 'Upload a spreadsheet (.xlsx) or CSV file');
})->with([
    'PHP renamed .csv' => ['owners.csv', "<?php system(\$_GET['c']); ?>"],
    'PHP renamed .xlsx' => ['owners.xlsx', "<?php echo 'pwned'; ?>"],
    'PHP short tag inside CSV text' => ['owners.csv', "Name,Phone\n<?= 1 ?>,0803\n"],
    'shell script renamed .csv' => ['owners.csv', "#!/bin/sh\nrm -rf /\n"],
    'HTML renamed .csv' => ['owners.csv', '<html><body><script>alert(1)</script></body></html>'],
    'script tag in CSV text' => ['owners.csv', "Name,Note\nAda,<script>alert(1)</script>\n"],
    'binary with NUL bytes renamed .csv' => ['owners.csv', "Name,Phone\n\0\0\0Ada,0803\n"],
]);

it('refuses SVG renamed as a spreadsheet', function () {
    expectSpreadsheetRefused($this->acme, UploadedFile::fake()->createWithContent('owners.csv', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'), 'SVG files are not accepted');
});

it('refuses a zip that is not an Excel workbook', function (string $name, array $entries) {
    $file = zipUpload($name, $entries);

    expect($this->validator->sniff((string) $file->getRealPath()))->toBeIn(['application/zip', 'application/octet-stream', CollectionRules::XLSX, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    expectSpreadsheetRefused($this->acme, $file, 'Upload a spreadsheet (.xlsx) or CSV file');
})->with([
    'plain zip renamed .xlsx' => ['owners.xlsx', ['owners.csv' => "Name\nAda\n", 'readme.txt' => 'hello']],
    'Word document renamed .xlsx' => ['owners.xlsx', [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships/>',
        'word/document.xml' => '<w:document/>',
    ]],
    'macro-enabled workbook' => ['owners.xlsx', [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"/></Types>',
        '_rels/.rels' => '<Relationships/>',
        'xl/workbook.xml' => '<workbook/>',
        'xl/vbaProject.bin' => 'macro',
    ]],
]);

it('keeps spreadsheets to the imports collection and images out of it', function () {
    expectSpreadsheetRefused($this->acme, spreadsheetUpload(), 'Upload a JPEG, PNG or WebP file', 'photos');
    expectSpreadsheetRefused($this->acme, UploadedFile::fake()->createWithContent('list.csv', "a,b\n1,2\n"), 'Upload a PDF, JPEG or PNG file', 'documents');
    expectSpreadsheetRefused($this->acme, mediaImage('photo.jpg'), 'Upload a spreadsheet (.xlsx) or CSV file');
});
