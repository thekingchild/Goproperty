<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\TransactionTierVersion;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A Transaction Tier's terms as one version sets them (P06-08;
 * ONBOARDING_COMPLIANCE.md § 5.2–5.3). A null limit means no cap.
 */
final readonly class TierTerms
{
    /**
     * @param  list<ComplianceDocumentType>  $requiredDocuments
     * @param  list<ComplianceDocumentType>  $optionalDocuments
     */
    public function __construct(
        public TransactionTier $tier,
        public int $version,
        public string $name,
        public ?Money $annualLimit,
        public ?Money $singlePaymentLimit,
        public ?Money $reviewThreshold,
        public array $requiredDocuments,
        public array $optionalDocuments,
        public ?int $reviewSlaDays,
        public CarbonImmutable $effectiveFrom,
    ) {}

    public static function of(TransactionTierVersion $version): self
    {
        return new self(
            $version->tier,
            $version->version,
            $version->name,
            $version->annual_volume_limit_kobo !== null ? Money::kobo($version->annual_volume_limit_kobo) : null,
            $version->single_payment_limit_kobo !== null ? Money::kobo($version->single_payment_limit_kobo) : null,
            $version->review_threshold_kobo !== null ? Money::kobo($version->review_threshold_kobo) : null,
            $version->requiredDocuments(),
            $version->optionalDocuments(),
            $version->review_sla_days,
            $version->effective_from,
        );
    }

    /**
     * How the tier is activated, as the signup wizard says it (§ 5.2
     * "Activation"; docs/plans/P06-subtasks.md default 83).
     */
    public function activation(): string
    {
        if ($this->reviewSlaDays === null) {
            return 'Active straight away';
        }

        $review = $this->tier === TransactionTier::Three ? 'Compliance review and a call' : 'Compliance review';
        $days = $this->reviewSlaDays === 1 ? '1 business day' : "{$this->reviewSlaDays} business days";

        return "{$review}, about {$days}";
    }
}
