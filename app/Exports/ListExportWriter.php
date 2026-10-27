<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a list export to the browser while it is read
 * (ARCHITECTURE.md § 2: OpenSpout, low memory): rows come from
 * ListExport::rows() a chunk at a time and go straight out, so a list of
 * any length needs no more memory than one chunk, and nothing is kept on
 * the server.
 *
 * CSV is written row by row to the response (UTF-8 with a byte-order mark
 * so Excel reads ₦ and accents, CRLF line ends). Excel needs a zip at the
 * end, so OpenSpout writes the sheet into a private folder made for this
 * one export (under `temp_root`), zips it into the response and removes
 * it; the folder is deleted in any case, even when the export fails half
 * way. Every cell goes through ExportCell (formula injection neutralised),
 * and Excel cells are made as text or numbers explicitly, never formulas.
 */
final readonly class ListExportWriter
{
    private string $tempRoot;

    public function __construct(?string $tempRoot = null)
    {
        $this->tempRoot = $tempRoot ?? storage_path('framework/exports');
    }

    /** @param  array<string, string|int|bool>  $filters  cleaned */
    public function response(ListExport $export, User $user, array $filters, ExportFormat $format, string $fileName): StreamedResponse
    {
        return new StreamedResponse(function () use ($export, $user, $filters, $format): void {
            match ($format) {
                ExportFormat::Csv => $this->writeCsv($export, $user, $filters),
                ExportFormat::Xlsx => $this->writeXlsx($export, $user, $filters),
            };
        }, 200, [
            'Content-Type' => $format->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            // Sent as it is written, not held back by a proxy (nginx).
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The cells of one row, in column order.
     *
     * @param  list<ExportColumn>  $columns
     * @return list<string|int|null>
     */
    public static function cells(array $columns, mixed $row): array
    {
        return array_map(static fn (ExportColumn $column): string|int|null => ExportCell::value(($column->value)($row)), $columns);
    }

    /** @param  array<string, string|int|bool>  $filters */
    private function writeCsv(ListExport $export, User $user, array $filters): void
    {
        $output = fopen('php://output', 'wb');

        if ($output === false) {
            throw new RuntimeException('The export could not be written.');
        }

        try {
            $columns = $export->columns($user);
            fwrite($output, "\u{FEFF}");
            fputcsv($output, array_map(static fn (ExportColumn $column): string => ExportCell::neutralise($column->header), $columns), ',', '"', '', "\r\n");
            $written = 0;

            foreach ($export->rows($user, $filters) as $row) {
                fputcsv($output, array_map(static fn (string|int|null $cell): string => (string) $cell, self::cells($columns, $row)), ',', '"', '', "\r\n");

                if (++$written % QueryListExport::CHUNK === 0) {
                    flush();
                }
            }
        } finally {
            fclose($output);
        }
    }

    /** @param  array<string, string|int|bool>  $filters */
    private function writeXlsx(ListExport $export, User $user, array $filters): void
    {
        $folder = $this->tempRoot.DIRECTORY_SEPARATOR.Str::random(32);

        if (! File::makeDirectory($folder, 0700, true, true)) {
            throw new RuntimeException('The export could not be written.');
        }

        try {
            $options = new Options;
            $options->setTempFolder($folder);
            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(self::sheetName($export->title()));
            $columns = $export->columns($user);
            $bold = (new Style)->setFontBold();
            $writer->addRow(new Row(array_map(static fn (ExportColumn $column): Cell => new StringCell(ExportCell::neutralise($column->header), null), $columns), $bold));

            foreach ($export->rows($user, $filters) as $row) {
                $writer->addRow(new Row(array_map(static fn (string|int|null $cell): Cell => match (true) {
                    $cell === null => new EmptyCell(null, null),
                    is_int($cell) => new NumericCell($cell, null),
                    default => new StringCell($cell, null),
                }, self::cells($columns, $row))));
            }

            // Zips the sheet into the response. Not reached when reading
            // failed: a half-written workbook is never sent as if complete.
            $writer->close();
        } finally {
            File::deleteDirectory($folder);
        }
    }

    /** Excel sheet names: at most 31 characters, none of : \ / ? * [ ]. */
    private static function sheetName(string $title): string
    {
        $name = trim(mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $title), 0, 31));

        return $name === '' ? 'Export' : $name;
    }
}
