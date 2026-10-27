<?php

declare(strict_types=1);

namespace Modules\Import\Reading;

use DateInterval;
use DateTimeInterface;
use Generator;
use Modules\Import\Enums\ImportFileType;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Sheet as CsvSheet;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;

/**
 * Streams a local .xlsx or .csv file row by row with OpenSpout (the reader
 * under spatie/simple-excel), so memory stays flat however long the sheet
 * is (DATA_IMPORT.md § 4): nothing ever holds a whole sheet. Empty rows are
 * kept so row numbers are the spreadsheet's own (errors name the exact
 * row). The header is the first non-empty row among the first
 * HEADER_SEARCH_ROWS (a title row above it is skipped).
 *
 * CSV: the delimiter (comma, semicolon or tab) is taken from the header
 * line, and a file that is not valid UTF-8 is read as Windows-1252 (what
 * Excel on Windows saves).
 */
final class SpreadsheetFile
{
    public const int HEADER_SEARCH_ROWS = 20;

    private function __construct(
        private readonly string $path,
        private readonly ImportFileType $type,
    ) {}

    public static function open(string $path, ImportFileType $type): self
    {
        return new self($path, $type);
    }

    /** @return list<array{index: int, name: string}> */
    public function sheets(): array
    {
        if ($this->type === ImportFileType::Csv) {
            return [['index' => 0, 'name' => 'CSV']];
        }

        $reader = $this->reader();
        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet instanceof SheetWithVisibilityInterface && ! $sheet->isVisible()) {
                    continue;
                }

                $sheets[] = ['index' => $sheet->getIndex(), 'name' => $sheet->getName()];
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * The header row's number (1-based) and its cells as text, or null for
     * a sheet with nothing in its first rows.
     *
     * @return array{row: int, headers: list<string>}|null
     */
    public function header(int $sheetIndex): ?array
    {
        foreach ($this->rows($sheetIndex, 1) as $number => $cells) {
            $headers = array_map(static fn (mixed $cell): string => self::headerText($cell), $cells);

            while ($headers !== [] && end($headers) === '') {
                array_pop($headers);
            }

            if ($headers !== []) {
                return ['row' => $number, 'headers' => $headers];
            }

            if ($number >= self::HEADER_SEARCH_ROWS) {
                return null;
            }
        }

        return null;
    }

    /**
     * The sheet's rows from `$fromRow` on (1-based), as row number =>
     * cells by position. Date cells are DateTimeInterface, numbers int or
     * float, formulas their computed value, error cells null.
     *
     * @return Generator<int, list<string|int|float|bool|DateTimeInterface|null>>
     */
    public function rows(int $sheetIndex, int $fromRow = 1): Generator
    {
        $reader = $this->reader();

        try {
            $sheet = $this->sheet($reader, $sheetIndex);

            if ($sheet === null) {
                return;
            }

            $number = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $number++;

                if ($number < $fromRow) {
                    continue;
                }

                yield $number => self::values($row);
            }
        } finally {
            $reader->close();
        }
    }

    /** @return list<string|int|float|bool|DateTimeInterface|null> */
    private static function values(Row $row): array
    {
        $values = [];
        $cells = $row->getCells();
        $last = $cells === [] ? -1 : max(array_keys($cells));

        for ($i = 0; $i <= $last; $i++) {
            $values[] = isset($cells[$i]) ? self::value($cells[$i]) : null;
        }

        return $values;
    }

    private static function value(Cell $cell): string|int|float|bool|DateTimeInterface|null
    {
        if ($cell instanceof ErrorCell) {
            return null;
        }

        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return $value instanceof DateInterval ? $value->format('%H:%I') : $value;
    }

    private static function headerText(mixed $cell): string
    {
        $text = match (true) {
            $cell instanceof DateTimeInterface => $cell->format('d/m/Y'),
            is_scalar($cell) => (string) $cell,
            default => '',
        };

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    private function sheet(CsvReader|XlsxReader $reader, int $index): CsvSheet|XlsxSheet|null
    {
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getIndex() === $index) {
                return $sheet;
            }
        }

        return null;
    }

    private function reader(): CsvReader|XlsxReader
    {
        if ($this->type === ImportFileType::Xlsx) {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        } else {
            $options = new CsvOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            [$options->FIELD_DELIMITER, $options->ENCODING] = $this->csvDialect();
            $reader = new CsvReader($options);
        }

        $reader->open($this->path);

        return $reader;
    }

    /** @return array{string, string} delimiter, encoding */
    private function csvDialect(): array
    {
        $head = (string) file_get_contents($this->path, false, null, 0, 65536);
        // Cut a multi-byte character split by the 64 KB limit before checking.
        $checked = strlen($head) === 65536 ? (string) preg_replace('/[\x80-\xFF]{1,3}$/', '', $head) : $head;
        $encoding = mb_check_encoding($checked, 'UTF-8') ? 'UTF-8' : 'Windows-1252';

        $firstLine = strtok(str_starts_with($head, "\u{FEFF}") ? substr($head, 3) : $head, "\r\n");
        $line = $firstLine === false ? '' : $firstLine;
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        return [$counts[$delimiter] > 0 ? $delimiter : ',', $encoding];
    }
}
