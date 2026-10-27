<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A statement line's place in matching (`bank_statement_lines.match_status`,
 * ONBOARDING_COMPLIANCE.md § 4.1). A credit starts `unmatched`. When
 * matching finds its bill (P03-07b) a payment waiting for confirmation is
 * recorded from it and the line is `suggested` (with its confidence) until
 * a member confirms that payment (`matched`) or rejects it (back to
 * `unmatched`, for an owner or administrator to match by hand). A credit
 * an owner or administrator matches by hand is `matched` at once. A debit
 * is money leaving the account, never a tenant's payment: it is `ignored`
 * from the start and never offered for matching.
 */
enum MatchStatus: string
{
    use HasValues;

    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Matched = 'matched';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'To match',
            self::Suggested => 'Waiting for confirmation',
            self::Matched => 'Matched',
            self::Ignored => 'Not matched (debit)',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Unmatched => 'amber',
            self::Suggested => 'sky',
            self::Matched => 'green',
            self::Ignored => 'zinc',
        };
    }

    /**
     * Whether a line in this state may still be matched: only a credit
     * nobody has matched yet. A `suggested` line already has its payment
     * waiting in the confirmation queue.
     */
    public function isOpen(): bool
    {
        return $this === self::Unmatched;
    }
}
