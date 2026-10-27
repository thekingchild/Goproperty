<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use App\Media\Media;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;

/**
 * The Import module's streaming spreadsheet reader, for other modules that
 * read an uploaded .xlsx or .csv file of their own (Collections' bank
 * statements, P03-07a) without importing records through ImportTarget.
 * Bound by ImportServiceProvider to the reader the import engine uses
 * (DATA_IMPORT.md § 4): OpenSpout, row by row, so memory stays flat however
 * long the sheet is; empty rows are kept, so row numbers are the file's
 * own; hidden sheets are skipped; formulas read as their computed value;
 * CSV delimiter (comma, semicolon or tab) and encoding (UTF-8, else
 * Windows-1252) are worked out from the file.
 *
 * Only files UploadValidator accepted as spreadsheets are read: `$mime` is
 * the type it sniffed (App\Media\CollectionRules::XLSX or ::CSV).
 */
interface SpreadsheetReader
{
    /**
     * The first visible sheet of a local file, as row number (1-based) =>
     * cells by position. Date cells are DateTimeInterface, numbers int or
     * float, formulas their computed value, error cells null.
     *
     * @return Generator<int, list<string|int|float|bool|DateTimeInterface|null>>
     *
     * @throws InvalidArgumentException when `$mime` is not a spreadsheet type
     * @throws UnreadableSpreadsheet when the file cannot be read as one (also while iterating)
     */
    public function rows(string $path, string $mime): Generator;

    /**
     * The same for a file in the media library (local disk or bucket): it
     * is copied to a private temporary file while it is read, and the copy
     * is deleted when the rows are done (or the generator is dropped).
     *
     * @return Generator<int, list<string|int|float|bool|DateTimeInterface|null>>
     *
     * @throws InvalidArgumentException when the media file is not a spreadsheet
     * @throws UnreadableSpreadsheet when the file is missing or cannot be read (also while iterating)
     */
    public function mediaRows(Media $media): Generator;
}
