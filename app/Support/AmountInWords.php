<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An amount in words for receipts and invoices (BRANDING_DOCUMENTS.md § 3,
 * § 5): "Two million, nine hundred thousand naira only", "One thousand,
 * two hundred and fifty naira and fifty kobo only". British usage, as on
 * Nigerian receipts: "and" after hundreds and before a last group below a
 * hundred, commas between groups, hyphens in 21–99. Works from the integer
 * kobo of App\Support\Money (no floats), for every amount Money can hold.
 */
final class AmountInWords
{
    private const array ONES = [
        '', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const array TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const array SCALES = ['', 'thousand', 'million', 'billion', 'trillion', 'quadrillion'];

    public static function of(Money $amount): string
    {
        $kobo = $amount->kobo;
        $negative = $kobo < 0;
        // Money never holds PHP_INT_MIN, so abs() stays an int.
        $kobo = abs($kobo);
        $naira = intdiv($kobo, 100);
        $rest = $kobo % 100;

        $parts = [];

        if ($naira > 0 || $rest === 0) {
            $parts[] = self::number($naira).' naira';
        }

        if ($rest > 0) {
            $parts[] = self::number($rest).' kobo';
        }

        $words = implode(' and ', $parts).' only';

        return ucfirst(($negative ? 'minus ' : '').$words);
    }

    /** A whole number in words, lower case: 1250 → "one thousand, two hundred and fifty". */
    public static function number(int $number): string
    {
        if ($number === 0) {
            return 'zero';
        }

        $groups = [];

        for ($scale = 0; $number > 0; $scale++) {
            $groups[$scale] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $words = [];

        foreach (array_reverse($groups, true) as $scale => $group) {
            if ($group === 0) {
                continue;
            }

            $text = self::belowThousand($group);

            // "One thousand and fifty": a last group below a hundred after higher ones.
            if ($scale === 0 && $group < 100 && $words !== []) {
                $words[] = 'and '.$text;

                continue;
            }

            $words[] = $text.(self::SCALES[$scale] !== '' ? ' '.self::SCALES[$scale] : '');
        }

        $last = array_pop($words);

        if ($words === []) {
            return (string) $last;
        }

        return str_starts_with((string) $last, 'and ')
            ? implode(', ', $words).' '.$last
            : implode(', ', $words).', '.$last;
    }

    private static function belowThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $words = $hundreds > 0 ? self::ONES[$hundreds].' hundred' : '';

        if ($rest === 0) {
            return $words;
        }

        return ($words !== '' ? $words.' and ' : '').self::belowHundred($rest);
    }

    private static function belowHundred(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $ones = $number % 10;

        return self::TENS[intdiv($number, 10)].($ones > 0 ? '-'.self::ONES[$ones] : '');
    }
}
