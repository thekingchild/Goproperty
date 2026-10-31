<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\ComplianceAudit;
use App\Compliance\TransactionTiers;
use App\Models\Organization;
use App\Models\OrganizationTierOverride;
use App\Models\PlatformStaff;
use App\Platform\Staff\StaffPermission;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Custom Transaction Tier limits for one organization (P06-08;
 * ONBOARDING_COMPLIANCE.md § 5.2 "Per-organization override",
 * PLATFORM_CONSOLE.md § 1A: compliance, no approval, reason and expiry
 * required; docs/plans/P06-subtasks.md default 79). The only writer of
 * `organization_tier_overrides`; App\Compliance\TransactionTiers reads it.
 *
 * - set(): a yearly and/or single-payment limit (null = the tier's own) with
 *   a reason and an end day (a Lagos day from tomorrow up to a year ahead; it
 *   stops at the start of that day); replaces the override in force.
 * - end(): ends the override in force now; the row is kept.
 *
 * Both need `customers.view` and `tiers.override` (re-checked here), run in
 * the organization's context under its lock, and write one audit entry (log
 * `compliance`, `tier_override.set` / `tier_override.ended`, the organization
 * as subject so it shows on its History, before and after). The owners are
 * not emailed (default 79).
 */
final readonly class TierOverrides
{
    public const int REASON_MIN = 3;

    public const int REASON_MAX = 500;

    public function __construct(
        private OrganizationContext $context,
        private TransactionTiers $tiers,
        private ComplianceAudit $audit,
    ) {}

    /**
     * @param  string  $until  the Lagos day it stops at the start of, Y-m-d
     *
     * @throws TierSettingsRefused
     */
    public function set(PlatformStaff $staff, Organization $organization, ?int $annualLimitKobo, ?int $singlePaymentLimitKobo, string $reason, string $until): OrganizationTierOverride
    {
        $this->authorize($staff);
        $reason = $this->reason($reason);
        $end = $this->endDay($until);

        if ($annualLimitKobo === null && $singlePaymentLimitKobo === null) {
            throw new TierSettingsRefused('Give a yearly limit, a single-payment limit or both.');
        }

        foreach ([$annualLimitKobo, $singlePaymentLimitKobo] as $kobo) {
            if ($kobo !== null && ($kobo < 100 || $kobo > TierSettings::MAX_KOBO)) {
                throw new TierSettingsRefused('A limit is between ₦1 and ₦1,000,000,000,000.');
            }
        }

        $terms = $this->tiers->terms($organization->transaction_tier);
        $annual = $annualLimitKobo ?? $terms->annualLimit?->kobo;
        $single = $singlePaymentLimitKobo ?? $terms->singlePaymentLimit?->kobo;

        if ($single !== null && $annual !== null && $single > $annual) {
            throw new TierSettingsRefused('The single-payment limit cannot be above the yearly limit ('.Money::kobo($annual)->format().').');
        }

        if ($single === null && $annual !== null) {
            throw new TierSettingsRefused('A yearly limit needs a single-payment limit too.');
        }

        return $this->inOrganization($organization, function () use ($staff, $organization, $annualLimitKobo, $singlePaymentLimitKobo, $reason, $end): OrganizationTierOverride {
            $override = OrganizationTierOverride::query()->lockForUpdate()->first();
            $before = $override instanceof OrganizationTierOverride && $override->inForce() ? $this->describe($override) : null;

            $override ??= new OrganizationTierOverride;
            $override->forceFill([
                'organization_id' => $organization->id,
                'annual_volume_limit_kobo' => $annualLimitKobo,
                'single_payment_limit_kobo' => $singlePaymentLimitKobo,
                'reason' => $reason,
                'set_by_staff_id' => $staff->id,
                // Stored in the application's time zone, as every other moment.
                'expires_at' => $end->setTimezone(date_default_timezone_get()),
            ])->save();

            $after = $this->describe($override);
            $this->audit->record('tier_override.set', $organization, $staff, "Custom Transaction Tier limits for {$organization->name} until {$end->format('j M Y')}", [
                'organization_id' => $organization->id,
                'tier' => $organization->transaction_tier->value,
                'old' => $before,
                'attributes' => $after,
            ], $reason);

            return $override;
        });
    }

    /** @throws TierSettingsRefused */
    public function end(PlatformStaff $staff, Organization $organization, ?string $reason = null): OrganizationTierOverride
    {
        $this->authorize($staff);
        $reason = $reason !== null && trim($reason) !== '' ? $this->reason($reason) : null;

        return $this->inOrganization($organization, function () use ($staff, $organization, $reason): OrganizationTierOverride {
            $override = OrganizationTierOverride::query()->lockForUpdate()->first();

            if (! $override instanceof OrganizationTierOverride || ! $override->inForce()) {
                throw new TierSettingsRefused("{$organization->name} has no custom Transaction Tier limits in force.");
            }

            $before = $this->describe($override);
            $override->forceFill(['expires_at' => CarbonImmutable::now()])->save();

            $this->audit->record('tier_override.ended', $organization, $staff, "Custom Transaction Tier limits ended for {$organization->name}: the tier's apply again", [
                'organization_id' => $organization->id,
                'tier' => $organization->transaction_tier->value,
                'old' => $before,
                'attributes' => null,
            ], $reason);

            return $override;
        });
    }

    /** The first and last end days staff may choose today (Lagos). */
    public static function firstEndDay(): CarbonImmutable
    {
        return CarbonImmutable::parse(TransactionTiers::day(), TransactionTiers::TIMEZONE)->startOfDay()->addDay();
    }

    public static function lastEndDay(): CarbonImmutable
    {
        return self::firstEndDay()->subDay()->addYearNoOverflow();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function inOrganization(Organization $organization, callable $change): mixed
    {
        return $this->context->run($organization, static fn (): mixed => DB::transaction(static function () use ($organization, $change): mixed {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            return $change();
        }, 3));
    }

    /** @return array{annual_volume_limit_kobo: int|null, single_payment_limit_kobo: int|null, until: string|null} */
    private function describe(OrganizationTierOverride $override): array
    {
        return [
            'annual_volume_limit_kobo' => $override->annual_volume_limit_kobo,
            'single_payment_limit_kobo' => $override->single_payment_limit_kobo,
            'until' => $override->expires_at?->toIso8601String(),
        ];
    }

    /** @throws TierSettingsRefused */
    private function endDay(string $date): CarbonImmutable
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, TransactionTiers::TIMEZONE);
        } catch (Throwable) {
            $day = null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw new TierSettingsRefused('Give the day the custom limits end.');
        }

        if ($day->lessThan(self::firstEndDay()) || $day->greaterThan(self::lastEndDay())) {
            throw new TierSettingsRefused('Custom limits end from tomorrow at the earliest and at most a year ahead.');
        }

        return $day;
    }

    /** @throws TierSettingsRefused */
    private function authorize(PlatformStaff $staff): void
    {
        if (! $staff->holdsStaffPermission(StaffPermission::CustomersView) || ! $staff->holdsStaffPermission(StaffPermission::TiersOverride)) {
            throw new TierSettingsRefused('You do not have permission to set custom Transaction Tier limits.');
        }
    }

    /** @throws TierSettingsRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new TierSettingsRefused('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters: it is kept with the limits and in the audit log.');
        }

        return $reason;
    }
}
