<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

/**
 * The key that keeps each bank transaction once (`bank_statement_lines.
 * line_hash`, unique per organization; ONBOARDING_COMPLIANCE.md § 4.1:
 * "Duplicate statement lines are ignored using a hash of date + amount +
 * narration + balance").
 *
 * The hash is SHA-256 of, in order: a version tag, the bank account, the
 * transaction date, the direction, the amount in kobo, the narration
 * normalised (lower case, letters and digits only, single spaces: a CSV and
 * an Excel export of the same statement agree), the running balance in
 * kobo when the statement has one, the bank's reference when it has one,
 * and the line's occurrence: 1 for the first line with all of the above in
 * the file, 2 for the second identical one, and so on.
 *
 * So the same statement uploaded twice, or an overlapping statement of the
 * same account, finds every line it shares with an earlier one and adds
 * only the new ones; and two genuinely identical credits on the same day
 * (same payer, amount and narration) stay two lines: the running balance
 * or reference tells them apart, and without either the occurrence does.
 * The occurrence counts within one file, so it needs statements that cover
 * whole days, as bank exports do; a statement cut in the middle of a day,
 * of an account whose export has neither balance nor reference, could take
 * the second of two identical lines of that day for the first.
 */
final class LineHash
{
    private const string VERSION = 'v1';

    /** Everything but the occurrence: lines with the same base are identical. */
    public static function base(int $bankAccountId, ParsedLine $line): string
    {
        return implode("\x1F", [
            self::VERSION,
            (string) $bankAccountId,
            $line->date->format('Y-m-d'),
            $line->direction->value,
            (string) $line->amountKobo,
            self::normalise($line->narration),
            $line->balanceKobo === null ? '' : (string) $line->balanceKobo,
            $line->reference === null ? '' : self::normalise($line->reference),
        ]);
    }

    public static function of(string $base, int $occurrence): string
    {
        return hash('sha256', $base."\x1F".$occurrence);
    }

    public static function normalise(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}
