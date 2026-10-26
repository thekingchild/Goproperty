<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\OrganizationType;

/** What the organization step collects (ONBOARDING_COMPLIANCE.md § 2 step 2), already validated. */
final readonly class OrganizationDetails
{
    public function __construct(
        public OrganizationType $type,
        public string $name,
        public string $state,
        public ?string $lga,
        public ?string $cacNumber,
    ) {}

    /**
     * Owners who run their own buildings and sign up directly have their
     * subscription paid by the platform owner (ARCHITECTURE.md § 6.1,
     * BILLING_PAYMENTS.md § 2): set at signup, from the type chosen there.
     */
    public function platformCovered(): bool
    {
        return $this->type === OrganizationType::PropertyOwner;
    }
}
