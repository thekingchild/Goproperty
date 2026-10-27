<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How sure a match is (ONBOARDING_COMPLIANCE.md § 4.1; filled by P03-07b):
 * the bill's reference in the narration is `high`; the same amount and a
 * payer name like the tenant's is `medium` (a suggestion).
 */
enum MatchConfidence: string
{
    use HasValues;

    case High = 'high';
    case Medium = 'medium';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Medium => 'Medium',
        };
    }
}
