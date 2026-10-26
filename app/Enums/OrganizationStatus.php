<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * DATABASE_SCHEMA.md § 1. While an organization is suspended or cancelled
 * its members can still sign in, read and export, but every write is
 * refused (App\Tenancy\OrganizationWriteGuard; BILLING_PAYMENTS.md § 2,
 * ONBOARDING_COMPLIANCE.md § 3). `restricted` (compliance, § 5.5) only
 * narrows what may be created, which the features concerned enforce.
 */
enum OrganizationStatus: string
{
    use HasValues;

    case Onboarding = 'onboarding';
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Members may read and export, never change anything. */
    public function blocksWrites(): bool
    {
        return $this === self::Suspended || $this === self::Cancelled;
    }

    /** Staff may suspend from these; reactivation returns to the one it left. */
    public function canBeSuspended(): bool
    {
        return in_array($this, [self::Onboarding, self::Active, self::Restricted], true);
    }
}
