<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Models\OrganizationTierOverride;
use App\Support\Money;

/**
 * The Transaction Tier limits that apply to one organization today (P06-08,
 * docs/plans/P06-subtasks.md default 80): its approved tier's terms in force,
 * with the limits of an override in force instead of the tier's where it
 * sets them. A null limit means no cap.
 */
final readonly class OrganizationTierLimits
{
    public function __construct(
        public TierTerms $terms,
        public ?OrganizationTierOverride $override,
    ) {}

    public function annualLimit(): ?Money
    {
        $custom = $this->override?->annual_volume_limit_kobo;

        return $custom !== null ? Money::kobo($custom) : $this->terms->annualLimit;
    }

    public function singlePaymentLimit(): ?Money
    {
        $custom = $this->override?->single_payment_limit_kobo;

        return $custom !== null ? Money::kobo($custom) : $this->terms->singlePaymentLimit;
    }

    public function hasOverride(): bool
    {
        return $this->override !== null;
    }
}
