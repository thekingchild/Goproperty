<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * One data row of a sheet, by column key (only the columns the agency
 * mapped), with its spreadsheet row number (the header is row 1). Values
 * are as the file had them: text, numbers, booleans, or dates for Excel
 * date cells. The helpers read them the way DATA_IMPORT.md § 4 promises:
 * dates as DD/MM/YYYY or date cells, amounts with or without ₦ and commas.
 */
final readonly class ImportRow
{
    /** @param  array<string, string|int|float|bool|DateTimeInterface|null>  $values */
    public function __construct(
        public string $sheet,
        public int $number,
        public array $values,
    ) {}

    /** Whether the agency mapped the column. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function value(string $key): string|int|float|bool|DateTimeInterface|null
    {
        return $this->values[$key] ?? null;
    }

    /** Whether every mapped cell is empty (blank rows are ignored). */
    public function isBlank(): bool
    {
        foreach (array_keys($this->values) as $key) {
            if ($this->text($key) !== null) {
                return false;
            }
        }

        return true;
    }

    /** The cell as trimmed text, null when empty. Whole numbers lose their ".0"; date cells read DD/MM/YYYY. */
    public function text(string $key): ?string
    {
        $value = $this->value($key);

        $text = match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('d/m/Y'),
            is_bool($value) => $value ? 'Yes' : 'No',
            is_int($value) => (string) $value,
            // A number cell: whole numbers (phones, counts) without ".0".
            is_float($value) => floor($value) === $value && abs($value) < 1e15 ? number_format($value, 0, '.', '') : (string) $value,
            default => $value,
        };

        $text = trim(str_replace("\u{00A0}", ' ', $text));

        return $text === '' ? null : $text;
    }

    public function externalRef(): ?string
    {
        return $this->text(ImportColumn::EXTERNAL_REF);
    }

    /**
     * A date: an Excel date cell, or text as DD/MM/YYYY (also D/M/YYYY,
     * DD-MM-YYYY, DD.MM.YYYY and YYYY-MM-DD). Null when empty.
     *
     * @throws InvalidCellValue
     */
    public function date(string $key): ?CarbonImmutable
    {
        $value = $this->value($key);

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        $text = $this->text($key);

        if ($text === null) {
            return null;
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new InvalidCellValue(__('Enter the date as DD/MM/YYYY, such as 31/12/2026.'));
        }

        if (! checkdate($month, $day, $year) || $year < 1900 || $year > 2200) {
            throw new InvalidCellValue(__('":date" is not a real date. Enter it as DD/MM/YYYY.', ['date' => $text]));
        }

        return CarbonImmutable::create($year, $month, $day)?->startOfDay()
            ?? throw new InvalidCellValue(__('Enter the date as DD/MM/YYYY, such as 31/12/2026.'));
    }

    /**
     * An amount in naira, returned in kobo (AGENT.md § 3 rule 2): 150000,
     * 150,000, ₦150,000.50, N150,000 or NGN 150000. Null when empty.
     *
     * @throws InvalidCellValue
     */
    public function amountKobo(string $key): ?int
    {
        $value = $this->value($key);
        $message = __('Enter an amount in naira, such as 150,000 or ₦150,000.50.');

        if (is_int($value)) {
            return Money::naira($value)->kobo;
        }

        if (is_float($value)) {
            // A number cell arrives as a float; read it as text with two
            // decimals, refusing fractions of a kobo rather than rounding.
            $text = sprintf('%.2F', $value);

            if (abs((float) $text - $value) > 1e-9) {
                throw new InvalidCellValue(__('Use at most two decimals (kobo).'));
            }
        } else {
            $text = $this->text($key);

            if ($text === null) {
                return null;
            }

            $text = (string) preg_replace('/^(-?)\s*(?:NGN|N)\s*/i', '$1', str_replace(' ', '', $text));
        }

        try {
            return Money::naira($text)->kobo;
        } catch (Throwable) {
            throw new InvalidCellValue($message);
        }
    }

    /**
     * A whole number (counts, bedrooms). Null when empty.
     *
     * @throws InvalidCellValue
     */
    public function integer(string $key): ?int
    {
        $value = $this->value($key);

        if (is_int($value)) {
            return $value;
        }

        $text = $this->text($key);

        if ($text === null) {
            return null;
        }

        $digits = str_replace(',', '', $text);

        if (preg_match('/^-?\d{1,15}$/', $digits) !== 1) {
            throw new InvalidCellValue(__('Enter a whole number, such as 3.'));
        }

        return (int) $digits;
    }

    /**
     * One of fixed values, in any letter case; returns the option as listed.
     * Null when empty.
     *
     * @param  list<string>  $options
     *
     * @throws InvalidCellValue
     */
    public function choice(string $key, array $options): ?string
    {
        $text = $this->text($key);

        if ($text === null) {
            return null;
        }

        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($text)) {
                return $option;
            }
        }

        throw new InvalidCellValue(__('Choose one of: :options.', ['options' => implode(', ', $options)]));
    }
}
