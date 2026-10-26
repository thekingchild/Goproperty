<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The organization's **approved** tier (ONBOARDING_COMPLIANCE.md § 5). Limits
 * and required documents per tier live there and in the compliance module.
 */
enum TransactionTier: int
{
    use HasValues;

    case One = 1;
    case Two = 2;
    case Three = 3;

    public function label(): string
    {
        return 'Tier '.$this->value;
    }
}
