<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use DateTimeInterface;
use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\StatementField;
use Modules\Collections\Models\BankStatementColumnMapping;

/**
 * Where a statement's table starts and what its columns are called. Bank
 * exports put a title, the account's name and number and the period above
 * the table, so the header is the first of the first SEARCH_ROWS rows that
 * names a date column and at least two other statement columns (Narration,
 * Debit, Credit, Balance, …; StatementField aliases). The rows above it
 * (the preamble) are kept to check which account the statement is for.
 */
final readonly class StatementLayout
{
    public const int SEARCH_ROWS = 30;

    /**
     * @param  list<string>  $labels  the header row's cells as text
     * @param  list<list<mixed>>  $preamble  the rows above it
     */
    private function __construct(
        public int $headerRow,
        public array $labels,
        public array $preamble,
    ) {}

    /**
     * Reads the first rows of a statement.
     *
     * @param  iterable<int, list<mixed>>  $rows  row number => cells
     */
    public static function find(iterable $rows): ?self
    {
        $preamble = [];
        $fallback = null;

        foreach ($rows as $number => $cells) {
            $labels = array_map(static fn (mixed $cell): string => self::text($cell), $cells);

            if (self::looksLikeHeader($labels)) {
                return new self($number, $labels, $preamble);
            }

            // Headings no alias knows: the first row with three or more
            // cells is the header, and the member maps its columns.
            if ($fallback === null && count(array_filter($labels, static fn (string $label): bool => $label !== '')) >= 3) {
                $fallback = new self($number, $labels, $preamble);
            }

            $preamble[] = $cells;

            if ($number >= self::SEARCH_ROWS) {
                break;
            }
        }

        return $fallback;
    }

    /**
     * The mapping to offer: the account's saved one when every header it
     * names is in this file, else one worked out from the headers.
     */
    public function suggest(?BankStatementColumnMapping $saved): StatementMapping
    {
        return $this->fromSaved($saved) ?? $this->guess();
    }

    /** The account's saved mapping applied to this file, or null when it does not fit. */
    public function fromSaved(?BankStatementColumnMapping $saved): ?StatementMapping
    {
        if ($saved === null || $saved->columns === []) {
            return null;
        }

        $columns = [];
        $headers = [];

        foreach ($saved->columns as $field => $header) {
            $index = $this->indexOf(StatementField::normalise((string) $header));

            if ($index === null || StatementField::tryFrom((string) $field) === null) {
                return null;
            }

            $columns[(string) $field] = $index;
            $headers[(string) $field] = $this->labels[$index];
        }

        $mapping = new StatementMapping($this->headerRow, $columns, $headers, $saved->date_order);

        return $mapping->problems() === [] ? $mapping : null;
    }

    /**
     * A mapping from the page's choices: field => column position (or
     * empty), checked against this header row.
     *
     * @param  array<string, mixed>  $columns
     */
    public function mappingFrom(array $columns, DateOrder $order): StatementMapping
    {
        $chosen = [];
        $headers = [];

        foreach (StatementField::cases() as $field) {
            $value = $columns[$field->value] ?? null;
            $index = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);

            if ($index !== null && isset($this->labels[$index]) && $this->labels[$index] !== '') {
                $chosen[$field->value] = $index;
                $headers[$field->value] = $this->labels[$index];
            }
        }

        return new StatementMapping($this->headerRow, $chosen, $headers, $order);
    }

    /**
     * Account numbers (10 digits, NUBAN) the rows above the table name next
     * to the word "account", such as "Account Number: 0123456789".
     *
     * @return list<string>
     */
    public function accountNumbers(): array
    {
        $numbers = [];

        foreach ($this->preamble as $cells) {
            $texts = [];

            foreach ($cells as $cell) {
                // A number cell loses the leading zero of 0123456789.
                $texts[] = is_int($cell) && $cell > 0 && strlen((string) $cell) >= 9 && strlen((string) $cell) <= 10
                    ? str_pad((string) $cell, 10, '0', STR_PAD_LEFT)
                    : self::text($cell);
            }

            $line = implode(' ', $texts);

            if (preg_match('/\b(account|acct|a\/c)\b/i', $line) !== 1) {
                continue;
            }

            preg_match_all('/(?<!\d)(\d{10})(?!\d)/', $line, $matches);

            foreach ($matches[1] as $number) {
                $numbers[$number] = $number;
            }
        }

        return array_values($numbers);
    }

    /**
     * The columns' header texts, for the mapping form: position => text
     * (empty ones left out).
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return array_filter($this->labels, static fn (string $label): bool => $label !== '');
    }

    /** A mapping worked out from the header names (StatementField aliases). */
    public function guess(): StatementMapping
    {
        $columns = [];
        $headers = [];
        $used = [];
        $normalised = array_map(static fn (string $label): string => StatementField::normalise($label), $this->labels);

        $pick = function (StatementField $field, bool $hints) use (&$columns, &$headers, &$used, $normalised): void {
            foreach ($field->aliases() as $alias) {
                foreach ($normalised as $index => $label) {
                    if ($label === $alias && ! isset($used[$index])) {
                        $columns[$field->value] = $index;
                        $headers[$field->value] = $this->labels[$index];
                        $used[$index] = true;

                        return;
                    }
                }
            }

            $hint = $hints ? $field->hint() : null;

            if ($hint === null) {
                return;
            }

            foreach ($normalised as $index => $label) {
                if ($label !== '' && str_contains($label, $hint) && ! isset($used[$index])) {
                    $columns[$field->value] = $index;
                    $headers[$field->value] = $this->labels[$index];
                    $used[$index] = true;

                    return;
                }
            }
        };

        foreach ([StatementField::Date, StatementField::Narration, StatementField::Credit, StatementField::Debit, StatementField::Balance, StatementField::Reference, StatementField::Counterparty] as $field) {
            $pick($field, true);
        }

        if (! isset($columns[StatementField::Credit->value]) && ! isset($columns[StatementField::Debit->value])) {
            $pick(StatementField::Amount, false);

            if (isset($columns[StatementField::Amount->value])) {
                $pick(StatementField::Direction, false);
            }
        }

        return new StatementMapping($this->headerRow, $columns, $headers);
    }

    private function indexOf(string $normalised): ?int
    {
        foreach ($this->labels as $index => $label) {
            if ($normalised !== '' && StatementField::normalise($label) === $normalised) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<string>  $labels */
    private static function looksLikeHeader(array $labels): bool
    {
        $found = [];

        foreach ($labels as $label) {
            $normalised = StatementField::normalise($label);

            if ($normalised === '' || strlen($normalised) > 40) {
                continue;
            }

            foreach (StatementField::cases() as $field) {
                if (in_array($normalised, $field->aliases(), true) || ($field === StatementField::Date && str_contains($normalised, 'date'))) {
                    $found[$field->value] = true;
                }
            }
        }

        return isset($found[StatementField::Date->value]) && count($found) >= 3;
    }

    private static function text(mixed $cell): string
    {
        $text = match (true) {
            $cell instanceof DateTimeInterface => $cell->format('d/m/Y'),
            is_scalar($cell) => (string) $cell,
            default => '',
        };

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
