<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One billing period of a cycle (CycleCalculator): its number counted from
 * the anchor (0 = the period that starts on the anchor; negative numbers
 * come before it), its first and last day (both inclusive) and its length
 * in calendar days. Dates are midnight in the anchor's time zone. Only
 * CycleCalculator makes these.
 */
final readonly class BillingPeriod
{
    public function __construct(
        public int $index,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $lengthInDays,
    ) {}

    /** Whether the date's calendar day falls in the period (time of day ignored). */
    public function contains(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->start->format('Y-m-d') && $day <= $this->end->format('Y-m-d');
    }
}
