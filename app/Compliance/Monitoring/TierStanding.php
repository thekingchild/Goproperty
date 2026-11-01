<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\OrganizationStatus;
use App\Enums\RestrictionSource;
use App\Enums\TierApplicationStatus;
use App\Models\ComplianceFlag;
use App\Models\TierApplication;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;

/**
 * What the workspace's Transaction Tier banner says (P06-09;
 * ONBOARDING_COMPLIANCE.md § 5.5 steps 1–3, default 94), for the
 * organization in context: restricted (every member), upgrade required
 * (the open `volume_100` flag, with its grace end), or approaching the
 * limit (`volume_80` / `forecast_over`). Reads only.
 */
final readonly class TierStanding
{
    public const string RESTRICTED = 'restricted';

    public const string UPGRADE_REQUIRED = 'upgrade_required';

    public const string APPROACHING = 'approaching';

    public function __construct(private OrganizationContext $context) {}

    /**
     * @return array{state: string, staff: bool, grace_ends_on: CarbonImmutable|null, application_sent: bool, amount: Money|null, limit: Money|null, forecast: bool}|null
     */
    public function current(): ?array
    {
        if (! $this->context->has()) {
            return null;
        }

        $organization = $this->context->get();
        $applicationSent = TierApplication::query()
            ->whereIn('status', [TierApplicationStatus::Submitted->value, TierApplicationStatus::UnderReview->value, TierApplicationStatus::MoreInfoNeeded->value])
            ->exists();
        $base = ['staff' => false, 'grace_ends_on' => null, 'application_sent' => $applicationSent, 'amount' => null, 'limit' => null, 'forecast' => false];

        if ($organization->status === OrganizationStatus::Restricted) {
            return ['state' => self::RESTRICTED, ...$base, 'staff' => $organization->restriction_source === RestrictionSource::Staff];
        }

        $flags = ComplianceFlag::query()
            ->whereIn('type', [ComplianceFlagType::Volume100->value, ComplianceFlagType::Volume80->value, ComplianceFlagType::ForecastOver->value])
            ->whereIn('status', ComplianceFlagStatus::openValues())
            ->where('tier', $organization->transaction_tier->value)
            ->get();

        $over = $flags->first(fn (ComplianceFlag $flag): bool => $flag->type === ComplianceFlagType::Volume100);

        if ($over instanceof ComplianceFlag) {
            return [...$base,
                'state' => self::UPGRADE_REQUIRED,
                'grace_ends_on' => $over->grace_ends_at?->setTimezone('Africa/Lagos'),
                'amount' => $over->metric_value_kobo !== null ? Money::kobo($over->metric_value_kobo) : null,
                'limit' => $over->limit_kobo !== null ? Money::kobo($over->limit_kobo) : null,
            ];
        }

        $near = $flags->first(fn (ComplianceFlag $flag): bool => $flag->type === ComplianceFlagType::Volume80)
            ?? $flags->first(fn (ComplianceFlag $flag): bool => $flag->type === ComplianceFlagType::ForecastOver);

        if ($near instanceof ComplianceFlag) {
            return [...$base,
                'state' => self::APPROACHING,
                'amount' => $near->metric_value_kobo !== null ? Money::kobo($near->metric_value_kobo) : null,
                'limit' => $near->limit_kobo !== null ? Money::kobo($near->limit_kobo) : null,
                'forecast' => $near->type === ComplianceFlagType::ForecastOver,
            ];
        }

        return null;
    }
}
