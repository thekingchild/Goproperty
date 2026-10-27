<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\StatementField;

/**
 * Reads one row of a statement with its mapping (ONBOARDING_COMPLIANCE.md
 * § 4.1): a transaction (ParsedLine), nothing (a blank row, the opening or
 * closing balance, a total, a narration carried onto the next row: no
 * money on it), or UnreadableRow with the reason for the report.
 *
 * Money: separate credit and debit columns (a minus in the debit column is
 * ignored, banks print debits either way; a negative credit, or both on
 * one row, is unreadable), or one amount column whose sign, CR / DR mark
 * or Dr/Cr column says the direction (negative or DR = debit). Amounts in
 * exact kobo (StatementAmount), dates in the statement's order
 * (StatementDate), no later than tomorrow (Lagos) and not before 2000.
 */
final readonly class StatementRowParser
{
    public const int NARRATION_LENGTH = 500;

    public const int REFERENCE_LENGTH = 100;

    public const int COUNTERPARTY_LENGTH = 150;

    public function __construct(
        private StatementMapping $mapping,
        private CarbonImmutable $today,
    ) {}

    /**
     * @param  list<mixed>  $cells
     *
     * @throws UnreadableRow
     */
    public function parse(int $rowNumber, array $cells): ?ParsedLine
    {
        $cell = fn (StatementField $field): mixed => $this->mapping->has($field) ? ($cells[(int) $this->mapping->column($field)] ?? null) : null;
        $dateCell = $cell(StatementField::Date);

        if (self::isEmpty($dateCell) && $this->isSummary($cells)) {
            return null;
        }

        [$direction, $amount] = $this->money($cell);

        if ($direction === null) {
            return null;
        }

        $date = StatementDate::read($dateCell, $this->mapping->dateOrder);

        if ($date->year < 2000) {
            throw new UnreadableRow(__('The date :date is too far back.', ['date' => $date->format('d/m/Y')]));
        }

        if ($date->greaterThan($this->today->addDay())) {
            throw new UnreadableRow(__('The date :date is in the future.', ['date' => $date->format('d/m/Y')]));
        }

        $balance = StatementAmount::read($cell(StatementField::Balance), __('balance'));

        return new ParsedLine(
            rowNumber: $rowNumber,
            date: $date,
            direction: $direction,
            amountKobo: $amount,
            narration: self::text($cell(StatementField::Narration), self::NARRATION_LENGTH) ?? '',
            counterparty: self::text($cell(StatementField::Counterparty), self::COUNTERPARTY_LENGTH),
            reference: self::text($cell(StatementField::Reference), self::REFERENCE_LENGTH),
            balanceKobo: $balance === null ? null : ($balance['mark'] === LineDirection::Debit ? -abs($balance['kobo']) : $balance['kobo']),
        );
    }

    /**
     * The direction and positive kobo, or [null, 0] when the row moves no money.
     *
     * @param  callable(StatementField): mixed  $cell
     * @return array{LineDirection|null, int}
     *
     * @throws UnreadableRow
     */
    private function money(callable $cell): array
    {
        if ($this->mapping->splitsMoney()) {
            $credit = StatementAmount::read($cell(StatementField::Credit), __('credit'));
            $debit = StatementAmount::read($cell(StatementField::Debit), __('debit'));

            if ($credit !== null && $debit !== null) {
                throw new UnreadableRow(__('The row has both a credit and a debit.'));
            }

            if ($credit !== null) {
                if ($credit['kobo'] < 0 || $credit['mark'] === LineDirection::Debit) {
                    throw new UnreadableRow(__('The credit is a negative amount.'));
                }

                return [LineDirection::Credit, $credit['kobo']];
            }

            return $debit === null ? [null, 0] : [LineDirection::Debit, abs($debit['kobo'])];
        }

        $amount = StatementAmount::read($cell(StatementField::Amount), __('amount'));

        if ($amount === null) {
            return [null, 0];
        }

        if ($this->mapping->has(StatementField::Direction)) {
            $direction = self::direction($cell(StatementField::Direction));
        } else {
            $direction = $amount['mark'] ?? ($amount['kobo'] < 0 ? LineDirection::Debit : LineDirection::Credit);
        }

        return [$direction, abs($amount['kobo'])];
    }

    /** @throws UnreadableRow */
    private static function direction(mixed $cell): LineDirection
    {
        $value = is_scalar($cell) ? strtoupper(trim((string) preg_replace('/[^A-Za-z+-]/', '', (string) $cell))) : '';

        return match ($value) {
            'CR', 'C', 'CREDIT', '+' => LineDirection::Credit,
            'DR', 'D', 'DEBIT', '-' => LineDirection::Debit,
            default => throw new UnreadableRow($value === ''
                ? __('The Dr / Cr column is empty.')
                : __('The Dr / Cr column says ":value", not CR or DR.', ['value' => mb_substr(trim((string) $cell), 0, 20)])),
        };
    }

    /**
     * A total or balance line at the top or bottom of the table (no date
     * of its own).
     *
     * @param  list<mixed>  $cells
     */
    private function isSummary(array $cells): bool
    {
        $text = implode(' ', array_map(static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '', $cells));

        return preg_match('/\b(total|totals|opening|closing|balance|b\/f|c\/f|brought forward|carried forward)\b/i', $text) === 1;
    }

    private static function isEmpty(mixed $cell): bool
    {
        return $cell === null || (is_string($cell) && trim($cell) === '');
    }

    private static function text(mixed $cell, int $length): ?string
    {
        $text = match (true) {
            $cell instanceof DateTimeInterface => $cell->format('d/m/Y'),
            is_scalar($cell) && ! is_bool($cell) => (string) $cell,
            default => '',
        };
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }
}
