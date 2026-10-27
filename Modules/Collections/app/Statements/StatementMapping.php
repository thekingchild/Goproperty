<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\StatementField;

/**
 * Which column of a statement holds each field, as used for one file: the
 * header row's number (1-based, the file's own), the column of each mapped
 * field (0-based position in that row) with its header text, and how the
 * file writes dates. Stored on the import (`column_mapping`); its header
 * texts are what the account's saved mapping keeps.
 */
final readonly class StatementMapping
{
    /**
     * @param  array<string, int>  $columns  field value => column position
     * @param  array<string, string>  $headers  field value => header text
     */
    public function __construct(
        public int $headerRow,
        public array $columns,
        public array $headers,
        public DateOrder $dateOrder = DateOrder::DayFirst,
    ) {}

    public function column(StatementField $field): ?int
    {
        return $this->columns[$field->value] ?? null;
    }

    public function has(StatementField $field): bool
    {
        return isset($this->columns[$field->value]);
    }

    /** Whether the money comes in separate credit and debit columns (else one amount column). */
    public function splitsMoney(): bool
    {
        return $this->has(StatementField::Credit) || $this->has(StatementField::Debit);
    }

    /**
     * What keeps the mapping from reading the statement, by field: the date
     * and narration columns, and the money in credit/debit columns or one
     * amount column (not both); a Dr/Cr column only beside an amount; no
     * column used twice.
     *
     * @return array<string, string> field value => message
     */
    public function problems(): array
    {
        $problems = [];

        foreach ([StatementField::Date, StatementField::Narration] as $field) {
            if (! $this->has($field)) {
                $problems[$field->value] = __('Choose the column that holds the :field.', ['field' => mb_strtolower(__($field->label()))]);
            }
        }

        $amount = $this->has(StatementField::Amount);

        if (! $amount && ! $this->splitsMoney()) {
            $problems[StatementField::Credit->value] = __('Choose the credit and debit columns, or the one amount column.');
        } elseif ($amount && $this->splitsMoney()) {
            $problems[StatementField::Amount->value] = __('Use either credit and debit columns or one amount column, not both.');
        }

        if ($this->has(StatementField::Direction) && ! $amount) {
            $problems[StatementField::Direction->value] = __('A Dr / Cr column only goes with one amount column.');
        }

        $seen = [];

        foreach (StatementField::cases() as $field) {
            $column = $this->column($field);

            if ($column === null) {
                continue;
            }

            if (isset($seen[$column])) {
                $problems[$field->value] ??= __('This column is already used for :field.', ['field' => mb_strtolower(__($seen[$column]->label()))]);
            }

            $seen[$column] = $field;
        }

        return $problems;
    }

    /** @return array{header_row: int, columns: array<string, int>, headers: array<string, string>, date_order: string} */
    public function toArray(): array
    {
        return [
            'header_row' => $this->headerRow,
            'columns' => $this->columns,
            'headers' => $this->headers,
            'date_order' => $this->dateOrder->value,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $columns = [];
        $headers = [];

        foreach (StatementField::cases() as $field) {
            $column = is_array($data['columns'] ?? null) ? ($data['columns'][$field->value] ?? null) : null;

            if (is_int($column) && $column >= 0) {
                $columns[$field->value] = $column;
                $header = is_array($data['headers'] ?? null) ? ($data['headers'][$field->value] ?? null) : null;
                $headers[$field->value] = is_string($header) ? $header : '';
            }
        }

        return new self(
            headerRow: is_int($data['header_row'] ?? null) ? $data['header_row'] : 1,
            columns: $columns,
            headers: $headers,
            dateOrder: DateOrder::tryFrom(is_string($data['date_order'] ?? null) ? $data['date_order'] : '') ?? DateOrder::DayFirst,
        );
    }
}
