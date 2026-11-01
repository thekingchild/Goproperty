<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\TransactionTiers;
use App\Enums\ComplianceFlagType;
use App\Enums\OrganizationStatus;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\OrganizationVolumeSnapshot;
use App\Notifications\TierMonitoringNotice;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Leasing\Contracts\RentForecast;
use Modules\Payments\Contracts\CollectionVolumes;

/**
 * The Transaction Tier monitoring of one organization (P06-09;
 * ONBOARDING_COMPLIANCE.md § 5.1, § 5.4–5.5; defaults 86–96 of
 * docs/plans/P06-subtasks.md).
 *
 * - daily() runs every day at 03:30 (routes/console.php, and by hand as
 *   `php artisan compliance:evaluate-tiers`) for every organization that
 *   is not cancelled: the day's snapshot, the volume and forecast flags,
 *   the month's spike, then the restriction.
 * - evaluate() measures one organization and brings its volume flags and
 *   restriction in line; PaymentChecks calls it after each confirmed
 *   payment (without the forecast, snapshot and spike, which are daily).
 *
 * Each organization is measured inside its context and a transaction that
 * holds its row, so a payment's check and the daily run wait for each
 * other. Owners are told of new flags after the commit (TierMonitoringNotice).
 */
final readonly class TierMonitor
{
    public function __construct(
        private OrganizationContext $context,
        private TransactionTiers $tiers,
        private CollectionVolumes $volumes,
        private RentForecast $rentForecast,
        private ComplianceFlags $flags,
        private TierRestrictions $restrictions,
        private MonitoringRules $rules,
        private OrganizationOwners $owners,
    ) {}

    /** @return array{organizations: int, flags: int} */
    public function daily(): array
    {
        $counts = ['organizations' => 0, 'flags' => 0];

        Organization::query()
            ->where('status', '!=', OrganizationStatus::Cancelled->value)
            ->orderBy('id')
            ->chunkById(100, function (Collection $organizations) use (&$counts): void {
                foreach ($organizations as $organization) {
                    /** @var Organization $organization */
                    $counts['organizations']++;
                    $counts['flags'] += count($this->evaluate($organization, daily: true));
                }
            });

        return $counts;
    }

    /**
     * Measures the organization and raises, resolves and acts on its flags.
     *
     * @return list<ComplianceFlag> the flags raised
     */
    public function evaluate(Organization $organization, bool $daily = false): array
    {
        return $this->context->run($organization, fn (): array => DB::transaction(function () use ($organization, $daily): array {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $measure = $this->measure($locked, $daily);

            if ($daily) {
                $this->snapshot($locked, $measure);
            }

            $raised = $this->volumeFlags($locked, $measure, $daily);

            if ($daily) {
                $spike = $this->spike($locked, $measure);
                $raised = $spike instanceof ComplianceFlag ? [...$raised, $spike] : $raised;
            }

            if ($locked->status === OrganizationStatus::Active || $locked->status === OrganizationStatus::Restricted) {
                $this->restrictions->reconcile($locked);
            }

            $this->tellOwners($locked, $raised);

            return $raised;
        }, 3));
    }

    /** What the organization collected and will collect, on today's Lagos day. Inside its context. */
    public function measure(Organization $organization, bool $withForecast = true): VolumeMeasure
    {
        $today = MonitoringRules::day();
        $tomorrow = $today->addDay();

        return new VolumeMeasure(
            day: $today,
            limits: $this->tiers->forOrganization($organization),
            rolling: $this->volumes->collectedBetween($today->subDays(364), $tomorrow),
            forecast: $withForecast ? $this->rentForecast->expectedBetween($tomorrow, $tomorrow->addYearNoOverflow()) : Money::zero(),
            largest: $this->volumes->largestBetween($today->subDays(364), $tomorrow),
            month: $this->volumes->collectedBetween($today->startOfMonth(), $tomorrow),
        );
    }

    private function snapshot(Organization $organization, VolumeMeasure $measure): void
    {
        $snapshot = OrganizationVolumeSnapshot::query()->whereDate('date', $measure->day->toDateString())->first()
            ?? new OrganizationVolumeSnapshot;

        $snapshot->forceFill([
            'organization_id' => $organization->id,
            'date' => $measure->day->toDateString(),
            'tier' => $organization->transaction_tier,
            'rolling_12m_volume_kobo' => $measure->rolling->kobo,
            'forecast_12m_kobo' => $measure->forecast->kobo,
            'largest_payment_kobo' => $measure->largest->kobo,
            'month_volume_kobo' => $measure->month->kobo,
            'annual_limit_kobo' => $measure->annualLimit()?->kobo,
            'single_payment_limit_kobo' => $measure->limits->singlePaymentLimit()?->kobo,
        ])->save();
    }

    /**
     * `volume_80`, `volume_100` and (daily) `forecast_over`: resolves the
     * episodes the measure no longer supports, opens new ones.
     *
     * @return list<ComplianceFlag>
     */
    private function volumeFlags(Organization $organization, VolumeMeasure $measure, bool $withForecast): array
    {
        $tier = $organization->transaction_tier;
        $limit = $measure->annualLimit();
        $types = $withForecast
            ? [ComplianceFlagType::Volume80, ComplianceFlagType::Volume100, ComplianceFlagType::ForecastOver]
            : [ComplianceFlagType::Volume80, ComplianceFlagType::Volume100];
        $raised = [];

        foreach ($types as $type) {
            $over = match ($type) {
                ComplianceFlagType::Volume80 => $measure->reaches($this->rules->warningPercent()),
                ComplianceFlagType::Volume100 => $measure->reaches(100),
                default => $measure->forecastOver(),
            };

            foreach ($this->flags->openFlags($type) as $flag) {
                if ($flag->tier !== $tier) {
                    $this->flags->resolveBySystem($flag, "The organization moved to {$tier->label()}.");
                } elseif ($limit === null) {
                    $this->flags->resolveBySystem($flag, 'No yearly limit applies to the organization any more.');
                } elseif (! $over) {
                    $this->flags->resolveBySystem($flag, match ($type) {
                        ComplianceFlagType::Volume80 => 'The yearly collections fell back under '.$this->rules->warningPercent().' % of the limit.',
                        ComplianceFlagType::Volume100 => 'The yearly collections fell back under the limit.',
                        default => 'The forecast fell back under the yearly limit.',
                    });
                }
            }

            if (! $over || $limit === null) {
                continue;
            }

            $metric = $type === ComplianceFlagType::ForecastOver ? $measure->forecast : $measure->rolling;
            $attributes = [
                'metric_value_kobo' => $metric->kobo,
                'limit_kobo' => $limit->kobo,
                'details' => ['percent' => $measure->percent()],
            ];

            if ($type === ComplianceFlagType::Volume100) {
                [$graceEndsAt, $loweredOn] = $this->graceEnd($organization, $measure);
                $attributes['grace_ends_at'] = $graceEndsAt->setTimezone(date_default_timezone_get());
                $attributes['details'] = [...$attributes['details'], 'limit_lowered_on' => $loweredOn?->toDateString()];
            }

            $flag = $this->flags->openVolumeEpisode($type, $tier, $attributes);

            if ($flag instanceof ComplianceFlag) {
                $raised[] = $flag;
            }
        }

        return $raised;
    }

    /**
     * When a new `volume_100` flag's grace ends: the start of the Lagos day
     * `grace_days` from today, or `lowered_limit_days` after the tier's
     * limit was lowered when that put the organization over it (§ 5.2,
     * default 95), whichever is later. Also returns the day the limit was
     * lowered, when that applies.
     *
     * @return array{CarbonImmutable, CarbonImmutable|null}
     */
    private function graceEnd(Organization $organization, VolumeMeasure $measure): array
    {
        $graceEnd = $measure->day->addDays($this->rules->graceDays());

        if ($measure->limits->override?->annual_volume_limit_kobo !== null) {
            return [$graceEnd, null];
        }

        $version = $this->tiers->versionInForce($organization->transaction_tier);
        $since = $version !== null ? CarbonImmutable::parse($version->effective_from->toDateString(), $measure->day->getTimezone())->startOfDay() : null;

        if ($since === null || $since->lessThanOrEqualTo($measure->day->subDays($this->rules->loweredLimitDays()))) {
            return [$graceEnd, null];
        }

        $before = $this->tiers->versionInForce($organization->transaction_tier, $since->subDay());
        $previous = $before?->annual_volume_limit_kobo;
        $current = $version->annual_volume_limit_kobo;
        $lowered = $current !== null && ($previous === null || $previous > $current);

        if (! $lowered || ($previous !== null && $measure->rolling->kobo > $previous)) {
            return [$graceEnd, null];
        }

        $loweredEnd = $since->addDays($this->rules->loweredLimitDays());

        return [$loweredEnd->greaterThan($graceEnd) ? $loweredEnd : $graceEnd, $since];
    }

    /** `spike`: the month so far over `spike_factor` × the average of the full months before it (default 92). */
    private function spike(Organization $organization, VolumeMeasure $measure): ?ComplianceFlag
    {
        $months = $this->rules->spikeMonths();
        $monthStart = $measure->day->startOfMonth();
        $average = $this->volumes->collectedBetween($monthStart->subMonthsNoOverflow($months), $monthStart)->dividedBy($months);
        $line = $average->times($this->rules->spikeFactor());

        if (! $average->isPositive() || $measure->month->lessThan($this->rules->spikeMinimum()) || ! $measure->month->greaterThan($line)) {
            return null;
        }

        return $this->flags->raise(ComplianceFlagType::Spike, "spike:{$organization->id}:{$monthStart->format('Y-m')}", [
            'tier' => $organization->transaction_tier,
            'metric_value_kobo' => $measure->month->kobo,
            'limit_kobo' => $line->kobo,
            'details' => ['month' => $monthStart->format('Y-m'), 'average_kobo' => $average->kobo, 'months' => $months],
        ]);
    }

    /**
     * The owners hear of the flags § 5.4 tells them about; of two volume
     * flags raised together only the more serious.
     *
     * @param  list<ComplianceFlag>  $raised
     */
    public function tellOwners(Organization $organization, array $raised): void
    {
        $types = array_map(static fn (ComplianceFlag $flag): ComplianceFlagType => $flag->type, $raised);

        foreach ($raised as $flag) {
            $skip = ($flag->type === ComplianceFlagType::Volume80 && in_array(ComplianceFlagType::Volume100, $types, true))
                || ($flag->type === ComplianceFlagType::ForecastOver
                    && (in_array(ComplianceFlagType::Volume80, $types, true) || in_array(ComplianceFlagType::Volume100, $types, true)));
            $notice = TierMonitoringNotice::forFlag($organization, $flag);

            if ($notice === null) {
                continue;
            }

            $flag->forceFill(['owners_notified_at' => now()])->save();

            if ($skip) {
                continue;
            }

            foreach ($this->owners->owners($organization) as $owner) {
                $owner->notify($notice);
            }
        }
    }
}
