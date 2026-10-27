<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

/**
 * The bill payment references a transfer narration may hold
 * (ONBOARDING_COMPLIANCE.md § 4.1: "Put GP-7KQ2M9X in your transfer
 * narration"), as candidates for Billing's Contracts\BillLedger::
 * findByReference, which decides whether one is a bill of the organization.
 *
 * Case, spaces and dashes do not matter (a payer types "gp 7kq2m9x",
 * "GP7KQ2M9X" or "GP–7KQ 2M9X"; banks squeeze spaces): the narration is
 * upper-cased and its spaces and dashes removed, then every "GP" followed
 * by 7 letters or digits is a candidate (O, I and L are read as 0, 1 and 1
 * by the ledger). Removing spaces can also join words into a candidate
 * that is no reference ("BIG PAYMENT" → "GPAYMENT"): the ledger finds no
 * bill for it, so it costs one lookup. At most MAX candidates a line.
 */
final class NarrationReferences
{
    public const int MAX = 5;

    /** @return list<string> candidates as "GP-XXXXXXX", in narration order, without repeats */
    public static function in(string $narration): array
    {
        $compact = mb_strtoupper((string) preg_replace('/[\s\p{Pd}]+/u', '', $narration));
        preg_match_all('/(?=GP([0-9A-Z]{7}))/', $compact, $matches);
        $found = [];

        foreach ($matches[1] as $code) {
            $candidate = 'GP-'.$code;

            if (! in_array($candidate, $found, true)) {
                $found[] = $candidate;
            }

            if (count($found) >= self::MAX) {
                break;
            }
        }

        return $found;
    }
}
