<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Support\Money;

final readonly class Plan
{
    /**
     * @param  list<string>  $features
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
    ) {}

    /** Monthly price for a number of units. */
    public function priceFor(int $units): Money
    {
        return $this->base->plus($this->extraUnit?->times(max(0, $units - $this->includedUnits)) ?? Money::zero());
    }
}
