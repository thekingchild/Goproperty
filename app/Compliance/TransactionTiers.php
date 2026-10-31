<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\TierVersionStatus;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\OrganizationTierOverride;
use App\Models\TransactionTierVersion;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The Transaction Tiers' terms in force (P06-08; ONBOARDING_COMPLIANCE.md
 * § 5.2): for a tier on a Lagos day, the latest **approved** version of
 * `transaction_tiers` whose `effective_from` is on or before that day; a
 * version waiting for its second approver, rejected, expired or cancelled
 * never applies. For an organization, its approved tier's terms today with
 * an override in force (`organization_tier_overrides`). The one reader of
 * both tables for the signup wizard, Tier applications, compliance review
 * and P06-09's monitoring.
 */
final readonly class TransactionTiers
{
    public const string TIMEZONE = 'Africa/Lagos';

    public function __construct(private OrganizationContext $context) {}

    /** @param  CarbonInterface|null  $day  a moment; its Lagos day counts (default today) */
    public function terms(TransactionTier $tier, ?CarbonInterface $day = null): TierTerms
    {
        $version = $this->versionInForce($tier, $day);

        return $version !== null
            ? TierTerms::of($version)
            : throw new LogicException("No approved terms for {$tier->label()} on ".self::day($day).'.');
    }

    /**
     * Every tier's terms in force on the day, Tier 1 first.
     *
     * @return list<TierTerms>
     */
    public function all(?CarbonInterface $day = null): array
    {
        return array_map(fn (TransactionTier $tier): TierTerms => $this->terms($tier, $day), TransactionTier::cases());
    }

    public function versionInForce(TransactionTier $tier, ?CarbonInterface $day = null): ?TransactionTierVersion
    {
        return TransactionTierVersion::query()
            ->where('tier', $tier->value)
            ->where('status', TierVersionStatus::Approved->value)
            ->whereDate('effective_from', '<=', self::day($day))
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /** The limits that apply to the organization today: its tier's, or an override's in force. */
    public function forOrganization(Organization $organization): OrganizationTierLimits
    {
        return new OrganizationTierLimits(
            $this->terms($organization->transaction_tier),
            $this->overrideInForce($organization),
        );
    }

    public function overrideInForce(Organization $organization): ?OrganizationTierOverride
    {
        $override = $this->context->run($organization, static fn (): ?OrganizationTierOverride => OrganizationTierOverride::query()->first());

        return $override instanceof OrganizationTierOverride && $override->inForce() ? $override : null;
    }

    /** The Lagos date (Y-m-d) of the moment, or of now. */
    public static function day(?CarbonInterface $moment = null): string
    {
        return CarbonImmutable::instance($moment ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->toDateString();
    }
}
