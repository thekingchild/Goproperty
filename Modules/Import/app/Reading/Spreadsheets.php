<?php

declare(strict_types=1);

namespace Modules\Import\Reading;

use App\Media\Media;
use Generator;
use InvalidArgumentException;
use Modules\Import\Contracts\SpreadsheetReader;
use Modules\Import\Contracts\UnreadableSpreadsheet;
use Modules\Import\Enums\ImportFileType;
use OpenSpout\Common\Exception\OpenSpoutException;
use RuntimeException;

/**
 * Contracts\SpreadsheetReader on the import engine's own reader
 * (SpreadsheetFile) and LocalCopy: the first visible sheet, streamed.
 * The reader's own exceptions come out as UnreadableSpreadsheet.
 */
final class Spreadsheets implements SpreadsheetReader
{
    public function rows(string $path, string $mime): Generator
    {
        $type = ImportFileType::fromMime($mime) ?? throw new InvalidArgumentException("\"{$mime}\" is not a spreadsheet type.");

        try {
            $file = SpreadsheetFile::open($path, $type);
            $sheets = $file->sheets();

            if ($sheets === []) {
                return;
            }

            yield from $file->rows($sheets[0]['index']);
        } catch (OpenSpoutException $exception) {
            throw new UnreadableSpreadsheet('The file could not be read as a spreadsheet.', previous: $exception);
        }
    }

    public function mediaRows(Media $media): Generator
    {
        $mime = (string) $media->mime_type;

        if (ImportFileType::fromMime($mime) === null) {
            throw new InvalidArgumentException("\"{$mime}\" is not a spreadsheet type.");
        }

        try {
            $path = LocalCopy::temporaryFile($media);
        } catch (RuntimeException $exception) {
            throw new UnreadableSpreadsheet($exception->getMessage(), previous: $exception);
        }

        try {
            yield from $this->rows($path, $mime);
        } finally {
            @unlink($path);
        }
    }
}
