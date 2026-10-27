<?php

declare(strict_types=1);

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the list export tests (P02-07), required by the files in
 * tests/Feature/Exports. Pages and exports go through the full stack, as
 * the browser does: the page, a Livewire update calling export(), then the
 * one-time download it redirects to. Buildings, units, owners and
 * assignments are made with the Properties module's test helpers.
 */

require_once __DIR__.'/../../../Modules/Properties/tests/Feature/Assignments/helpers.php';
require_once __DIR__.'/../../Support/impersonation.php';

/** A fresh request, as production starts every one. */
function exportsFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** A workspace page as the user ($path after /o/{slug}). */
function exportPage(User $user, string $path, string $slug = 'acme-homes'): TestResponse
{
    exportsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}{$path}");
}

/**
 * Presses Export on the page: loads it, applies the updates (filters typed
 * on the page) and calls export($list, $format) in one Livewire request.
 *
 * @param  array<string, mixed>  $updates
 */
function pressExport(User $user, string $path, string $list, string $format = 'csv', array $updates = [], string $slug = 'acme-homes'): TestResponse
{
    $snapshot = LivewireHttp::snapshot(exportPage($user, $path, $slug)->assertOk());
    exportsFreshRequest();

    return LivewireHttp::update(test()->actingAs($user), $snapshot, $updates, [['export', [$list, $format]]]);
}

/** Opens the download the Export button redirected to. */
function openDownload(User $user, TestResponse $pressed): TestResponse
{
    $url = LivewireHttp::redirect($pressed->assertOk());
    expect($url)->toBeString()->toContain('/exports/');
    exportsFreshRequest();

    return test()->actingAs($user)->get((string) $url);
}

/**
 * Exports through the page and returns the file's rows, header first.
 *
 * @param  array<string, mixed>  $updates
 * @return list<list<string>>
 */
function exportRows(User $user, string $path, string $list, string $format = 'csv', array $updates = [], string $slug = 'acme-homes'): array
{
    $download = openDownload($user, pressExport($user, $path, $list, $format, $updates, $slug))->assertOk();

    return fileRows($download->streamedContent(), $format);
}

/** @return list<list<string>> */
function fileRows(string $content, string $format): array
{
    return $format === 'csv' ? csvRows($content) : xlsxRows($content);
}

/** @return list<list<string>> */
function csvRows(string $content): array
{
    expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue();
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
    }

    fclose($stream);

    return $rows;
}

/** @return list<list<string>> */
function xlsxRows(string $content): array
{
    $path = tempnam(sys_get_temp_dir(), 'gp-export-test-');
    file_put_contents($path, $content);
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(static fn (mixed $value): string => (string) $value, $row->toArray());
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $rows;
}

/** The first sheet's XML of an .xlsx file. */
function xlsxSheetXml(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'gp-export-test-');
    file_put_contents($path, $content);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    return $xml;
}

/**
 * The values of one column of an export, by header.
 *
 * @param  list<list<string>>  $rows
 * @return list<string>
 */
function exportColumn(array $rows, string $header): array
{
    $index = array_search($header, $rows[0] ?? [], true);
    expect($index)->not->toBeFalse("No column \"{$header}\" in the export.");

    return array_map(static fn (array $row): string => $row[$index] ?? '', array_slice($rows, 1));
}
