<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use App\Plans\Feature;
use App\Plans\FeatureValue;

/**
 * The priced terms of a plan version as staff type them (P06-01a): kobo,
 * units, and one value per feature of the registry (a missing feature is
 * its default).
 */
final readonly class PlanTerms
{
    /** @param  array<string, FeatureValue>  $features  by Feature value */
    public function __construct(
        public int $basePriceKobo,
        public int $includedUnits,
        public ?int $unitPriceKobo,
        public array $features,
    ) {}

    public function feature(Feature $feature): FeatureValue
    {
        return $this->features[$feature->value] ?? $feature->default();
    }
}
