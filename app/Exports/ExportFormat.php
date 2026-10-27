<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\Concerns\HasValues;

/** The two formats every list exports to (DATA_IMPORT.md § 6). */
enum ExportFormat: string
{
    use HasValues;

    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
