<?php

declare(strict_types=1);

namespace Modules\Billing\Documents;

use App\Documents\Parts\DocumentDates;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One meter reading as a utility bill prints it (Documents\UtilityBillDocument;
 * BRANDING_DOCUMENTS.md § 4): the day it was read, the previous and current
 * values, the consumption between them, the rate per unit of measure and
 * what it came to, as the bill was issued (taken from the bill line's
 * `meta`). Values are already formatted for people ("12,345.6").
 */
final readonly class UtilityReadingLine
{
    public function __construct(
        public CarbonImmutable $readOn,
        public string $previous,
        public string $current,
        public string $consumption,
        public string $unit,
        public Money $rate,
        public Money $amount,
        public bool $meterReplaced = false,
    ) {}

    public function readOnLabel(): string
    {
        return DocumentDates::day($this->readOn);
    }

    /** "₦250 per kWh". */
    public function rateLabel(): string
    {
        return __(':amount per :unit', ['amount' => $this->rate->format(), 'unit' => $this->unit]);
    }
}
