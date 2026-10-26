<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\TransactionTier;
use App\Support\Money;

/** One Transaction Tier as the signup wizard explains it (ONBOARDING_COMPLIANCE.md § 5.2). */
final readonly class TierOption
{
    public function __construct(
        public TransactionTier $tier,
        public string $name,
        public ?Money $annualLimit,
        public ?Money $singlePaymentLimit,
        public string $suits,
        public string $activation,
    ) {}

    /** Whether the stated yearly collections fit under this tier's yearly limit. */
    public function fits(Money $annual): bool
    {
        return $this->annualLimit === null || $annual->lessThanOrEqual($this->annualLimit);
    }

    public function needsReview(): bool
    {
        return $this->tier !== TransactionTier::One;
    }
}
