<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A Transaction Tier application (ONBOARDING_COMPLIANCE.md § 2 and § 5.3,
 * DATABASE_SCHEMA.md § 9). The org-owner fills a `draft` and submits it;
 * compliance reviews it and approves, rejects or asks for more
 * information, which sends it back to the owner until they submit again.
 * Approved and rejected applications are closed; the owner may start a
 * new one. Changed only through App\Compliance\TierApplications (owner)
 * and App\Platform\Compliance\TierReview (staff).
 */
enum TierApplicationStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case MoreInfoNeeded = 'more_info_needed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::MoreInfoNeeded => 'More information needed',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Not decided yet: at most one open application per organization. */
    public function isOpen(): bool
    {
        return ! $this->isDecided();
    }

    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }

    /** The owner may change documents and submit (again). */
    public function isWithApplicant(): bool
    {
        return $this === self::Draft || $this === self::MoreInfoNeeded;
    }

    /** Waiting for compliance: staff may decide. */
    public function isWithCompliance(): bool
    {
        return $this === self::Submitted || $this === self::UnderReview;
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()));
    }
}
