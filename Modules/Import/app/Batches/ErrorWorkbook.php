<?php

declare(strict_types=1);

namespace Modules\Import\Batches;

use DateTimeInterface;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Templates\TemplateWriter;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The error report to download (DATA_IMPORT.md § 2.3): the dry run again,
 * written out as it goes. Each mapped sheet comes back with all its rows
 * as the file had them, plus a "Problems" column (column header, letter and
 * message for every error and warning) and a "Row in your file" column,
 * so the agency can fix the rows and upload the sheet again. A last sheet,
 * "All problems", lists every one: sheet, row, column, kind and message.
 */
final readonly class ErrorWorkbook
{
    public const string SUMMARY_SHEET = 'All problems';

    public function __construct(private DryRunner $dryRunner) {}

    public function write(ImportBatch $batch, string $sourcePath, string $path): void
    {
        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();
        $bold = (new Style)->setFontBold();
        $wrap = (new Style)->setShouldWrapText();
        $summary = [];
        $current = null;
        $names = [];

        $this->dryRunner->run($batch, $sourcePath, function (SheetMapping $sheet, int $number, array $cells, array $issues) use ($writer, $bold, $wrap, &$summary, &$current, &$names): void {
            /** @var list<RowIssue> $issues */
            foreach ($issues as $issue) {
                $summary[] = [
                    $sheet->name,
                    $number > 0 ? $number : '',
                    $issue->column !== null ? trim(($sheet->headerFor($issue->column) ?? $issue->column).' '.($sheet->letterFor($issue->column) !== null ? '('.$sheet->letterFor($issue->column).')' : '')) : '',
                    $issue->isError() ? __('Error') : __('Warning'),
                    $issue->message,
                ];
            }

            if ($number === 0) {
                return; // A problem with the whole sheet: in the summary only.
            }

            if ($current !== $sheet->index) {
                $name = self::uniqueName($sheet->name, $names);
                $current === null ? $writer->nameCurrentSheet($name) : $writer->addNewSheetAndMakeItCurrent($name);
                $current = $sheet->index;
                $writer->addRow([...$sheet->headers, __('Problems'), __('Row in your file')], $bold);
            }

            $values = array_map(
                static fn (mixed $cell): mixed => $cell instanceof DateTimeInterface || is_scalar($cell) ? $cell : null,
                array_pad(array_slice($cells, 0, count($sheet->headers)), count($sheet->headers), null),
            );

            $writer->addRow([...$values, self::describe($sheet, $issues), $number], $issues === [] ? null : $wrap);
        });

        $current === null ? $writer->nameCurrentSheet(self::SUMMARY_SHEET) : $writer->addNewSheetAndMakeItCurrent(self::SUMMARY_SHEET);
        $writer->addRow([__('Sheet'), __('Row'), __('Column'), __('Kind'), __('Problem')], $bold);

        if ($summary === []) {
            $writer->addRow([__('No problems found.')]);
        }

        foreach ($summary as $row) {
            $writer->addRow($row);
        }

        $writer->close();
    }

    /** @param  list<RowIssue>  $issues */
    public static function describe(SheetMapping $sheet, array $issues): string
    {
        return implode("\n", array_map(static function (RowIssue $issue) use ($sheet): string {
            $prefix = $issue->isError() ? '' : __('Warning').': ';

            if ($issue->column === null) {
                return $prefix.$issue->message;
            }

            $letter = $sheet->letterFor($issue->column);

            return $prefix.($sheet->headerFor($issue->column) ?? $issue->column).($letter !== null ? " ({$letter})" : '').': '.$issue->message;
        }, $issues));
    }

    /** @param  array<string, true>  $names */
    private static function uniqueName(string $name, array &$names): string
    {
        $base = TemplateWriter::sheetName($name);
        $candidate = $base;

        for ($n = 2; isset($names[mb_strtolower($candidate)]) || mb_strtolower($candidate) === mb_strtolower(self::SUMMARY_SHEET); $n++) {
            $candidate = mb_substr($base, 0, 27).' ('.$n.')';
        }

        $names[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
