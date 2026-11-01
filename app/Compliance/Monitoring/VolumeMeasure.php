<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\OrganizationTierLimits;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * What one organization collected and will collect, measured on a Lagos day
 * against the limits that apply to it (P06-09; ONBOARDING_COMPLIANCE.md
 * § 5.1): the rolling 12 months (the 365 days ending that day), the rent its
 * leases will bill over the next 12 months, the largest payment, and the
 * calendar month so far.
 */
final readonly class VolumeMeasure
{
    public function __construct(
        public CarbonImmutable $day,
        public OrganizationTierLimits $limits,
        public Money $rolling,
        public Money $forecast,
        public Money $largest,
        public Money $month,
    ) {}

    public function annualLimit(): ?Money
    {
        return $this->limits->annualLimit();
    }

    /** Whether the rolling 12 months reach $percent of the yearly limit (never without a limit). */
    public function reaches(int $percent): bool
    {
        $limit = $this->annualLimit();

        return $limit !== null && $this->rolling->times(100)->greaterThanOrEqual($limit->times($percent));
    }

    public function forecastOver(): bool
    {
        $limit = $this->annualLimit();

        return $limit !== null && $this->forecast->greaterThan($limit);
    }

    /** The rolling 12 months as a whole percentage of the yearly limit (null without one). */
    public function percent(): ?int
    {
        $limit = $this->annualLimit();

        return $limit !== null && $limit->isPositive() ? intdiv($this->rolling->kobo * 100, $limit->kobo) : null;
    }
}
