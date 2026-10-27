<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A statement line's place in matching (`bank_statement_lines.match_status`,
 * ONBOARDING_COMPLIANCE.md § 4.1). A credit starts `unmatched`; matching
 * (P03-07b) suggests a bill (`suggested`) or records a payment from it
 * (`matched`). A debit is money leaving the account, never a tenant's
 * payment: it is `ignored` from the start and never offered for matching.
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
            self::Suggested => 'Match suggested',
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

    /** Whether a line in this state is still a candidate for matching. */
    public function isOpen(): bool
    {
        return $this === self::Unmatched || $this === self::Suggested;
    }
}
