<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;
use App\Media\CollectionRules;

/**
 * The uploaded statement's format, from the type UploadValidator sniffed
 * (ONBOARDING_COMPLIANCE.md § 4.1: CSV and Excel at launch; PDF later, not
 * built).
 */
enum StatementFormat: string
{
    use HasValues;

    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public static function fromMime(string $mime): ?self
    {
        return match ($mime) {
            CollectionRules::CSV => self::Csv,
            CollectionRules::XLSX => self::Xlsx,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
        };
    }
}
