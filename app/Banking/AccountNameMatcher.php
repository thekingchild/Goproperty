<?php

declare(strict_types=1);

namespace App\Banking;

/**
 * Whether the name a bank returned for an account is the name the
 * organization gave. Banks print names in capitals, in their own word order
 * ("OKAFOR CHIAMAKA ADA"), with or without "Ltd", and may cut a long name
 * short, so the comparison is on words: case, punctuation, order and
 * company suffixes do not matter, and the bank's last word may be the start
 * of one of the given words (cut short; at least 4 letters). A one-word
 * name must match exactly; otherwise every word of the shorter name must be
 * in the longer one.
 */
final class AccountNameMatcher
{
    /** Words that say what kind of company it is, not which one. */
    private const array IGNORED = ['LTD', 'LIMITED', 'PLC', 'NIG', 'NIGERIA', 'CO', 'COMPANY', 'INC', 'THE', 'AND'];

    public function matches(string $given, string $bank): bool
    {
        $givenWords = $this->words($given);
        $bankWords = $this->words($bank);

        if ($givenWords === [] || $bankWords === []) {
            return false;
        }

        $cutShort = $bankWords[array_key_last($bankWords)];
        $pairs = fn (string $givenWord, string $bankWord): bool => $givenWord === $bankWord
            || ($bankWord === $cutShort && strlen($bankWord) >= 4 && str_starts_with($givenWord, $bankWord));

        if (count($givenWords) === 1 || count($bankWords) === 1) {
            return count($givenWords) === count($bankWords) && $pairs($givenWords[0], $bankWords[0]);
        }

        if (count($givenWords) <= count($bankWords)) {
            foreach ($givenWords as $givenWord) {
                if (! $this->any($bankWords, fn (string $bankWord): bool => $pairs($givenWord, $bankWord))) {
                    return false;
                }
            }

            return true;
        }

        foreach ($bankWords as $bankWord) {
            if (! $this->any($givenWords, fn (string $givenWord): bool => $pairs($givenWord, $bankWord))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> distinct, in the name's order */
    public function words(string $name): array
    {
        $name = strtoupper(str_replace('&', ' AND ', $name));
        $parts = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($parts, static fn (string $word): bool => ! in_array($word, self::IGNORED, true))));
    }

    /**
     * @param  list<string>  $words
     * @param  callable(string): bool  $test
     */
    private function any(array $words, callable $test): bool
    {
        foreach ($words as $word) {
            if ($test($word)) {
                return true;
            }
        }

        return false;
    }
}
