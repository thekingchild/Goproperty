<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

use Illuminate\Support\Str;

/**
 * Whether a statement line's payer looks like the tenant
 * (ONBOARDING_COMPLIANCE.md § 4.1 "payer name similar to the tenant",
 * P03-07b), deliberately simple so a member can predict it:
 *
 * 1. Both names become words: accents removed, upper case, split on
 *    anything that is not a letter; words of one letter and titles (Mr,
 *    Mrs, Dr, Chief, Alhaji, …) are dropped. The payer's words are the
 *    sender column's (when the statement has one) and the narration's.
 * 2. A tenant's word matches a payer's word when they are equal, or when
 *    the shorter has at least PREFIX letters and begins the longer (banks
 *    cut long names: "ADEBAY" for Adebayo).
 * 3. Similar = at least two of the tenant's words match; a tenant with a
 *    single word (a company called "Zenovia") needs that word, of at least
 *    PREFIX letters.
 *
 * Word order, extra words ("TRF FROM", "RENT JAN") and case never matter.
 * It only ever suggests: a member confirms or rejects the payment.
 */
final class NameSimilarity
{
    public const int PREFIX = 4;

    /** Titles and honorifics that say nothing about who paid. */
    private const array TITLES = [
        'MR', 'MRS', 'MS', 'MISS', 'MASTER', 'DR', 'PROF', 'ENGR', 'ARC', 'BARR', 'CHIEF', 'HRH', 'HON', 'SIR', 'DAME',
        'ALHAJI', 'ALHAJA', 'ALH', 'HAJIA', 'MALAM', 'PASTOR', 'REV', 'EVANG', 'APOSTLE', 'BISHOP', 'PRINCE', 'PRINCESS', 'MADAM', 'MAMA', 'OGA',
    ];

    public static function similar(string $tenantName, string $payer): bool
    {
        $tenant = self::words($tenantName);
        $payerWords = self::words($payer);

        if ($tenant === [] || $payerWords === []) {
            return false;
        }

        if (count($tenant) === 1 && strlen($tenant[0]) < self::PREFIX) {
            return false;
        }

        $matched = 0;

        foreach ($tenant as $word) {
            foreach ($payerWords as $other) {
                if (self::same($word, $other)) {
                    $matched++;

                    break;
                }
            }
        }

        return $matched >= min(2, count($tenant));
    }

    /** @return list<string> the name's words, as compared */
    public static function words(string $text): array
    {
        $words = preg_split('/[^A-Z]+/', strtoupper(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => strlen($word) >= 2 && ! in_array($word, self::TITLES, true),
        )));
    }

    private static function same(string $one, string $other): bool
    {
        if ($one === $other) {
            return true;
        }

        [$short, $long] = strlen($one) <= strlen($other) ? [$one, $other] : [$other, $one];

        return strlen($short) >= self::PREFIX && str_starts_with($long, $short);
    }
}
