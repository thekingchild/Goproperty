<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Support\Money;

/**
 * A plan with the terms of one of its versions (P06-01a; BILLING_PAYMENTS.md
 * § 1): what the landing page, signup, quotes and the plan gates read.
 * Built by PlanCatalogue from the `plans`, `plan_versions` and
 * `plan_feature` tables.
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $features  the landing page's lines (`plans.highlights`)
     * @param  array<string, FeatureValue>  $entitlements  by Feature value
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $audience,
        public Money $base,
        public int $includedUnits,
        public ?Money $extraUnit,
        public bool $featured,
        public bool $custom,
        public array $features,
        /** SMS credits included each month (BILLING_PAYMENTS.md § 1.2; the `sms_credits` limit). */
        public int $smsCredits = 0,
        public int $id = 0,
        public int $versionId = 0,
        public int $version = 0,
        public int $trialDays = 14,
        public array $entitlements = [],
        /** Offered to new signups (`plans.is_active`). */
        public bool $offered = true,
    ) {}

    /** Monthly price for a number of units. */
    public function priceFor(int $units): Money
    {
        return $this->base->plus($this->extraUnit?->times(max(0, $units - $this->includedUnits)) ?? Money::zero());
    }

    public function feature(Feature $feature): FeatureValue
    {
        return $this->entitlements[$feature->value] ?? $feature->default();
    }
}
