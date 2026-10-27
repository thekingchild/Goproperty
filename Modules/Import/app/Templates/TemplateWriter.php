<?php

declare(strict_types=1);

namespace Modules\Import\Templates;

use App\Theme\ThemeContext;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Mapping\SheetMapping;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelWriter;
use ZipArchive;

/**
 * The downloadable import template (DATA_IMPORT.md § 2.1): an
 * "Instructions" sheet, then one sheet per registered target (in commit
 * order) with its column headers and an example row, and dropdowns for
 * columns with fixed values. OpenSpout cannot write data validation, so the
 * dropdowns read their values from a hidden "Lists" sheet and are added to
 * each sheet's XML after the workbook is written.
 */
final readonly class TemplateWriter
{
    public const string LISTS_SHEET = 'Lists';

    /** Rows the dropdowns cover below the header (the per-sheet row limit). */
    private const int DROPDOWN_ROWS = 10_000;

    public function __construct(private ThemeContext $theme) {}

    /** @param  list<ImportTarget>  $targets */
    public function write(string $path, array $targets): void
    {
        $writer = SimpleExcelWriter::create($path, 'xlsx')->noHeaderRow();
        $bold = (new Style)->setFontBold();
        $writer->nameCurrentSheet('Instructions');

        foreach ($this->instructions($targets) as $i => $row) {
            $writer->addRow($row, $i === 0 || ($row[1] ?? null) === 'Column' ? $bold : null);
        }

        /** @var list<array{sheet: int, letter: string, options: list<string>}> $lists */
        $lists = [];

        foreach ($targets as $n => $target) {
            $writer->addNewSheetAndMakeItCurrent(self::sheetName($target->label()));
            $columns = $target->columns();
            $writer->addRow(array_map(static fn (ImportColumn $c): string => $c->label, $columns), $bold);

            if (array_filter($columns, static fn (ImportColumn $c): bool => $c->example !== null) !== []) {
                $writer->addRow(array_map(static fn (ImportColumn $c): string => (string) $c->example, $columns));
            }

            foreach ($columns as $position => $column) {
                if ($column->options !== []) {
                    $lists[] = ['sheet' => $n + 2, 'letter' => SheetMapping::letter($position), 'options' => $column->options];
                }
            }
        }

        if ($lists !== []) {
            $writer->addNewSheetAndMakeItCurrent(self::LISTS_SHEET);
            $longest = max(array_map(static fn (array $list): int => count($list['options']), $lists));

            for ($i = 0; $i < $longest; $i++) {
                $writer->addRow(array_map(static fn (array $list): string => $list['options'][$i] ?? '', $lists));
            }

            $workbook = $writer->getWriter();

            if ($workbook instanceof XlsxWriter) {
                $workbook->getCurrentSheet()->setIsVisible(false);
            }
        }

        $writer->close();

        if ($lists !== []) {
            $this->addDropdowns($path, $lists);
        }
    }

    /** A valid, distinct-enough sheet name (Excel: at most 31 characters, none of []:*?/\). */
    public static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name));

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }

    /**
     * @param  list<ImportTarget>  $targets
     * @return list<list<string>>
     */
    private function instructions(array $targets): array
    {
        $rows = [
            [__(':brand import template', ['brand' => $this->theme->current()->name()])],
            [],
            [__('1. Fill in one sheet for each kind of record, one row per record. Leave sheets you do not need empty.')],
            [__('2. Keep the first row of each sheet as it is: it names the columns. Delete the example row.')],
            [__('3. Dates as DD/MM/YYYY (for example 31/12/2026). Amounts in naira, with or without ₦ and commas.')],
            [__('4. "Your ID" is optional: your own ID for the row. Importing the same ID again updates the record instead of adding it twice.')],
            [__('5. Upload the file, check the column matching, and run the dry run: nothing is saved until you commit.')],
            [],
        ];

        foreach ($targets as $target) {
            $rows[] = [$target->label(), $target->instructions()];
            $rows[] = ['', 'Column', 'Required', 'Allowed values', 'Notes'];

            foreach ($target->columns() as $column) {
                $rows[] = ['', $column->label, $column->required ? __('Yes') : __('No'), implode(', ', $column->options), (string) $column->help];
            }

            $rows[] = [];
        }

        return $rows;
    }

    /** @param  list<array{sheet: int, letter: string, options: list<string>}>  $lists */
    private function addDropdowns(string $path, array $lists): void
    {
        $bySheet = [];

        foreach ($lists as $n => $list) {
            $source = sprintf('%s!$%s$1:$%s$%d', self::LISTS_SHEET, SheetMapping::letter($n), SheetMapping::letter($n), count($list['options']));
            $bySheet[$list['sheet']][] = sprintf(
                '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="%1$s2:%1$s%2$d"><formula1>%3$s</formula1></dataValidation>',
                $list['letter'],
                self::DROPDOWN_ROWS + 1,
                $source,
            );
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The template could not be reopened.');
        }

        try {
            foreach ($bySheet as $sheet => $validations) {
                $name = "xl/worksheets/sheet{$sheet}.xml";
                $xml = $zip->getFromName($name);

                if (! is_string($xml) || ! str_contains($xml, '</sheetData>')) {
                    throw new RuntimeException("The template sheet {$sheet} could not be read.");
                }

                $block = '<dataValidations count="'.count($validations).'">'.implode('', $validations).'</dataValidations>';
                [$before, $after] = explode('</sheetData>', $xml, 2);
                // Schema order: dataValidations come before these elements.
                $at = preg_match('/<(hyperlinks|printOptions|pageMargins|pageSetup|headerFooter|drawing|legacyDrawing|tableParts)\b|<\/worksheet>/', $after, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : strlen($after);
                $zip->addFromString($name, $before.'</sheetData>'.substr($after, 0, $at).$block.substr($after, $at));
            }
        } finally {
            $zip->close();
        }
    }
}
