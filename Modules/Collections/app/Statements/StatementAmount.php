<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use App\Support\Money;
use DateTimeInterface;
use Modules\Collections\Enums\LineDirection;
use Throwable;

/**
 * Reads a money cell of a bank statement into exact kobo (AGENT.md § 3
 * rule 2): never through float arithmetic. Text goes to App\Support\Money
 * as written ("1,250,000.50", "₦50,000", "NGN 1,000", "N2,500.00", with
 * thousands commas grouped correctly and at most two decimals), after
 * reading the sign banks use: a leading or trailing minus, brackets
 * ("(2,500.00)") and a CR / DR mark ("5,000.00 CR"). A number cell of an
 * Excel file arrives from the reader as an int or a float: a float is
 * written out with two decimals and accepted only when that text is
 * exactly the same number, so a value with fractions of a kobo is refused
 * rather than rounded. Empty cells, "-" and zero are no amount (null).
 */
final class StatementAmount
{
    /**
     * @return array{kobo: int, mark: LineDirection|null}|null signed kobo, and the CR / DR mark if the cell had one
     *
     * @throws UnreadableRow
     */
    public static function read(mixed $cell, string $what): ?array
    {
        if ($cell === null || $cell === '' || is_bool($cell) || $cell instanceof DateTimeInterface) {
            if (is_bool($cell) || $cell instanceof DateTimeInterface) {
                throw new UnreadableRow(__('The :what is not an amount.', ['what' => $what]));
            }

            return null;
        }

        if (is_int($cell)) {
            $kobo = self::money((string) $cell, $what);

            return $kobo === 0 ? null : ['kobo' => $kobo, 'mark' => null];
        }

        if (is_float($cell)) {
            if (! is_finite($cell)) {
                throw new UnreadableRow(__('The :what is not an amount.', ['what' => $what]));
            }

            $text = sprintf('%.2F', $cell);

            if ((float) $text !== $cell) {
                throw new UnreadableRow(__('The :what has more than two decimals (kobo).', ['what' => $what]));
            }

            $kobo = self::money($text, $what);

            return $kobo === 0 ? null : ['kobo' => $kobo, 'mark' => null];
        }

        $text = mb_strtoupper(trim((string) preg_replace('/[\s\x{00A0}]+/u', '', (string) $cell)));

        if ($text === '' || preg_match('/^-+$/', $text) === 1) {
            return null;
        }

        $mark = null;

        if (preg_match('/^(.*?)(CR|DR)\.?$/', $text, $match) === 1 && $match[1] !== '') {
            [$text, $mark] = [$match[1], $match[2] === 'CR' ? LineDirection::Credit : LineDirection::Debit];
        } elseif (preg_match('/^(CR|DR)\.?(.+)$/', $text, $match) === 1) {
            [$text, $mark] = [$match[2], $match[1] === 'CR' ? LineDirection::Credit : LineDirection::Debit];
        }

        $negative = false;

        if (preg_match('/^\((.+)\)$/', $text, $match) === 1) {
            [$text, $negative] = [$match[1], true];
        }

        if (str_ends_with($text, '-')) {
            [$text, $negative] = [substr($text, 0, -1), true];
        }

        if (str_starts_with($text, '-')) {
            [$text, $negative] = [substr($text, 1), ! $negative];
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        $text = (string) preg_replace('/^(?:₦|NGN|N)/u', '', $text);

        if (preg_match('/^[\d,]*\.\d{3,}$/', $text) === 1) {
            throw new UnreadableRow(__('The :what has more than two decimals (kobo).', ['what' => $what]));
        }

        $kobo = self::money($text, $what);

        return $kobo === 0 ? null : ['kobo' => $negative ? -$kobo : $kobo, 'mark' => $mark];
    }

    /** @throws UnreadableRow */
    private static function money(string $text, string $what): int
    {
        try {
            return Money::naira($text)->kobo;
        } catch (Throwable) {
            throw new UnreadableRow(__('The :what is not an amount in naira, such as 150,000.00.', ['what' => $what]));
        }
    }
}
