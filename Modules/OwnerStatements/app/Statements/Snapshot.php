<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Reading back a settled statement's snapshot (`owner_statement_settlements.figures`,
 * P08-05): JSON as stored (MySQL reorders object keys, so every value is
 * read by its key, never by position).
 */
final class Snapshot
{
    /** @param  array<string, mixed>  $data */
    public static function money(array $data, string $key): Money
    {
        $value = $data[$key] ?? 0;

        return Money::kobo(is_int($value) ? $value : (int) (is_numeric($value) ? $value : 0));
    }

    /** @param  array<string, mixed>  $data */
    public static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param  array<string, mixed>  $data */
    public static function nullableText(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @param  array<string, mixed>  $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param  array<string, mixed>  $data */
    public static function date(array $data, string $key): CarbonImmutable
    {
        return CarbonImmutable::parse(self::text($data, $key) !== '' ? self::text($data, $key) : '2000-01-01');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $rows = $data[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
