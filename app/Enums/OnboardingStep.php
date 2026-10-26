<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The signup wizard's steps, in order (ONBOARDING_COMPLIANCE.md § 2). The
 * first two belong to the account (the users table says where it stands:
 * created, email verified); from Organization on, the step is saved in
 * `organization_onboardings.step`, so leaving and coming back resumes there.
 */
enum OnboardingStep: string
{
    use HasValues;

    case Account = 'account';
    case VerifyEmail = 'verify_email';
    case Organization = 'organization';
    case Tier = 'tier';
    case Plan = 'plan';
    case Collections = 'collections';
    case Data = 'data';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Your account',
            self::VerifyEmail => 'Confirm your email',
            self::Organization => 'Your organization',
            self::Tier => 'Transaction Tier',
            self::Plan => 'Plan and free trial',
            self::Collections => 'Rent collection',
            self::Data => 'Your properties',
        };
    }

    /** 1-based position, for "Step 4 of 7". */
    public function number(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    public static function count(): int
    {
        return count(self::cases());
    }

    /** Steps the wizard saves on the organization (the rest are the account's). */
    public function isOrganizationStep(): bool
    {
        return $this !== self::Account && $this !== self::VerifyEmail;
    }

    /** What the member must be allowed to do in the organization to complete this step. */
    public function permission(): ?OrganizationPermission
    {
        return match ($this) {
            self::Account, self::VerifyEmail => null,
            self::Organization => OrganizationPermission::UpdateOrganization,
            self::Tier => OrganizationPermission::ManageCompliance,
            self::Plan => OrganizationPermission::ManageBilling,
            self::Collections => OrganizationPermission::ManagePaymentSettings,
            self::Data => OrganizationPermission::ManageImports,
        };
    }

    public function next(): ?self
    {
        return self::cases()[$this->number()] ?? null;
    }

    public function previous(): ?self
    {
        return $this->number() > 1 ? self::cases()[$this->number() - 2] : null;
    }

    public function isBefore(self $other): bool
    {
        return $this->number() < $other->number();
    }
}
