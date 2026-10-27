<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a statement line was matched (ONBOARDING_COMPLIANCE.md § 4.1,
 * P03-07b; Matching\MatchFinder): the bill's payment reference in the
 * narration is `high`; the same amount as a bill still owes and a payer
 * name like the tenant's is `medium` (a suggestion); `manual` is an owner
 * or administrator choosing the tenant and bills by hand.
 */
enum MatchConfidence: string
{
    use HasValues;

    case High = 'high';
    case Medium = 'medium';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Reference in the narration',
            self::Medium => 'Same amount, similar name',
            self::Manual => 'Matched by hand',
        };
    }
}
