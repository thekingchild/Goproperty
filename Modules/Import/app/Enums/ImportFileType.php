<?php

declare(strict_types=1);

namespace Modules\Import\Enums;

use App\Enums\Concerns\HasValues;

/** The uploaded file's format, from its sniffed type (DATA_IMPORT.md § 4). */
enum ImportFileType: string
{
    use HasValues;

    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public const string XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const string CSV_MIME = 'text/csv';

    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            self::XLSX_MIME => self::Xlsx,
            self::CSV_MIME => self::Csv,
            default => null,
        };
    }
}
