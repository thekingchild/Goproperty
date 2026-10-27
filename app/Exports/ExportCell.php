<?php

declare(strict_types=1);

namespace App\Exports;

use App\Support\Money;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stringable;
use UnitEnum;

/**
 * Turns a column's value into a spreadsheet cell, the same for CSV and
 * Excel: money as naira with two decimals from kobo ("1250000.00",
 * Money::toDecimalString, never a float), times in Africa/Lagos as
 * "2026-10-27 14:05" (a date alone, ExportCell::date(), as "2026-10-27":
 * unambiguous in every spreadsheet locale), enums by their label, true and
 * false as Yes and No, whole numbers as numbers, and text with formula
 * injection neutralised (neutralise()).
 */
final class ExportCell
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** A cell's value: text or a whole number; null is an empty cell. */
    public static function value(mixed $value): string|int|null
    {
        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof Money => $value->toDecimalString(),
            $value instanceof ExportDate => $value->format(),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i'),
            $value instanceof BackedEnum && method_exists($value, 'label') => self::neutralise((string) $value->label()),
            $value instanceof BackedEnum => self::neutralise((string) $value->value),
            $value instanceof UnitEnum => self::neutralise($value->name),
            is_string($value) => $value === '' ? null : self::neutralise($value),
            is_scalar($value), $value instanceof Stringable => self::neutralise((string) $value),
            default => null,
        };
    }

    /** A calendar date (no time of day), e.g. a lease's start: "2026-10-27". */
    public static function date(?DateTimeInterface $date): ?ExportDate
    {
        return $date === null ? null : new ExportDate($date);
    }

    /**
     * Formula injection (CSV injection): a cell starting with = + - @, a tab
     * or a carriage return is run as a formula by Excel, LibreOffice and
     * Google Sheets, so text people typed (a name, a note) could call out
     * or exfiltrate. Such text gets a leading apostrophe, which every
     * spreadsheet shows as text. Plain numbers ("-1", "+2348031234567")
     * stay as they are: they cannot call anything. Line breaks inside
     * text are kept (the CSV writer quotes them).
     */
    public static function neutralise(string $text): string
    {
        if ($text === '' || preg_match('/^[+-]?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }
}
