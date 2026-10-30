<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Models\PlanFeature;
use App\Models\PlanVersion;
use App\Models\SubscriptionPlan;
use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * GoProperty's plans and their terms (P06-01a; BILLING_PAYMENTS.md § 1),
 * read from the `plans`, `plan_versions` and `plan_feature` tables that
 * finance-admin edits in the console. A plan's terms on a day are those of
 * its latest version, not cancelled, in effect on or before that Lagos
 * date. Read by the landing page, signup, the SMS allowance, the quotes and
 * App\Plans\Features. VAT and the annual "pay 10 months" stay in
 * config/plans.php. Kept for the request (scoped binding): the tables are
 * read once per date.
 */
final class PlanCatalogue
{
    /** @var array<string, list<Plan>> every plan, by Lagos date */
    private array $byDate = [];

    public function __construct(private readonly Config $config) {}

    /**
     * The plans offered to new signups, with today's terms, in order.
     *
     * @return list<Plan>
     */
    public function plans(): array
    {
        return array_values(array_filter($this->all(), static fn (Plan $plan): bool => $plan->offered));
    }

    /**
     * Every plan, offered or not, with its terms on $date (today by default).
     * A plan with no version in effect on that date is left out.
     *
     * @return list<Plan>
     */
    public function all(?CarbonImmutable $date = null): array
    {
        $day = self::day($date);

        return $this->byDate[$day] ??= $this->load($day);
    }

    /** A plan by key with its terms on $date (offered or not); null when unknown. */
    public function find(string $key, ?CarbonImmutable $date = null): ?Plan
    {
        foreach ($this->all($date) as $plan) {
            if ($plan->key === $key) {
                return $plan;
            }
        }

        return null;
    }

    /** A plan by id with its terms on $date. */
    public function findById(int $id, ?CarbonImmutable $date = null): ?Plan
    {
        foreach ($this->all($date) as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }

        return null;
    }

    /** A plan with the terms of one version (scheduled or past), for notices and history. */
    public function withVersion(PlanVersion $version): Plan
    {
        $plan = SubscriptionPlan::query()->findOrFail($version->plan_id);

        return $this->build($plan, $version, PlanFeature::query()->where('plan_version_id', $version->id)->get()->all());
    }

    /** The next scheduled version of a plan after $date (not cancelled), if any. */
    public function scheduled(int $planId, ?CarbonImmutable $date = null): ?PlanVersion
    {
        return PlanVersion::query()
            ->where('plan_id', $planId)
            ->whereNull('cancelled_at')
            ->whereDate('effective_from', '>', self::day($date))
            ->orderBy('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    public function starter(): Plan
    {
        return $this->plans()[0] ?? throw new LogicException('No plan is offered.');
    }

    /** Forget what was read (after an edit in the same request). */
    public function flush(): void
    {
        $this->byDate = [];
    }

    public function vatPercent(): string
    {
        $vat = $this->config->get('plans.vat_percent', '7.5');

        return is_scalar($vat) ? (string) $vat : '7.5';
    }

    public function monthsPaidPerYear(): int
    {
        $months = $this->config->get('plans.months_paid_per_year', 10);

        return is_numeric($months) ? (int) $months : 10;
    }

    /** The trial length the signup pages advertise: the first offered plan's (14 days at launch). */
    public function trialDays(): int
    {
        $plans = $this->plans();

        if ($plans !== []) {
            return $plans[0]->trialDays;
        }

        $days = $this->config->get('plans.trial_days', 14);

        return is_numeric($days) ? (int) $days : 14;
    }

    /** The cheapest offered plan for a number of units, as the calculator picks it. */
    public function bestFor(int $units): Plan
    {
        $regular = array_values(array_filter($this->plans(), fn (Plan $plan): bool => ! $plan->custom));
        $custom = array_values(array_filter($this->plans(), fn (Plan $plan): bool => $plan->custom));

        if ($regular === []) {
            return $custom[0] ?? throw new LogicException('No plan is offered.');
        }

        usort($regular, fn (Plan $a, Plan $b): int => $a->priceFor($units)->compareTo($b->priceFor($units)));
        $best = $regular[0];

        if ($custom !== [] && ($units >= $custom[0]->includedUnits || $best->priceFor($units)->greaterThanOrEqual($custom[0]->base))) {
            return $custom[0];
        }

        return $best;
    }

    /**
     * For the calculator script (resources/js/marketing.js): whole naira.
     *
     * @return array<string, mixed>
     */
    public function forScript(): array
    {
        $regular = [];
        $enterprise = null;

        foreach ($this->plans() as $plan) {
            if ($plan->custom) {
                $enterprise ??= ['key' => $plan->key, 'name' => $plan->name, 'base' => intdiv($plan->base->kobo, 100), 'incl' => $plan->includedUnits];

                continue;
            }

            $regular[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'base' => intdiv($plan->base->kobo, 100),
                'incl' => $plan->includedUnits,
                'unit' => intdiv($plan->extraUnit->kobo ?? 0, 100),
            ];
        }

        return [
            'plans' => $regular,
            'enterprise' => $enterprise,
            'vat' => $this->vatPercent(),
            'yearMonths' => $this->monthsPaidPerYear(),
        ];
    }

    /** A Lagos date, "2026-12-01". */
    public static function day(?CarbonImmutable $date = null): string
    {
        return ($date ?? CarbonImmutable::now())->setTimezone('Africa/Lagos')->toDateString();
    }

    /** @return list<Plan> */
    private function load(string $day): array
    {
        $plans = SubscriptionPlan::query()->orderBy('sort_order')->orderBy('id')->get();

        if ($plans->isEmpty()) {
            return [];
        }

        // The version in effect on $day: the latest effective date, then the highest version.
        $versions = PlanVersion::query()
            ->whereIn('plan_id', $plans->modelKeys())
            ->whereNull('cancelled_at')
            ->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->get()
            ->unique('plan_id')
            ->keyBy('plan_id');

        $features = PlanFeature::query()->whereIn('plan_version_id', $versions->pluck('id'))->get()->groupBy('plan_version_id');
        $result = [];

        foreach ($plans as $plan) {
            $version = $versions->get($plan->id);

            if ($version instanceof PlanVersion) {
                $result[] = $this->build($plan, $version, $features->get($version->id)?->all() ?? []);
            }
        }

        return $result;
    }

    /** @param  list<PlanFeature>|array<int, PlanFeature>  $rows */
    private function build(SubscriptionPlan $plan, PlanVersion $version, array $rows): Plan
    {
        $entitlements = [];

        foreach ($rows as $row) {
            $value = $row->value();

            if ($value instanceof FeatureValue) {
                $entitlements[$row->feature_key] = $value;
            }
        }

        $highlights = array_values(array_filter($plan->highlights ?? [], 'is_string'));

        return new Plan(
            key: $plan->key,
            name: $plan->name,
            audience: $plan->audience,
            base: Money::kobo($version->base_price_kobo),
            includedUnits: $version->included_units,
            extraUnit: $version->unit_price_kobo === null ? null : Money::kobo($version->unit_price_kobo),
            featured: $plan->featured,
            custom: $plan->custom,
            features: $highlights,
            smsCredits: ($entitlements[Feature::SmsCredits->value] ?? Feature::SmsCredits->default())->limit ?? 0,
            id: $plan->id,
            versionId: $version->id,
            version: $version->version,
            trialDays: $plan->trial_days,
            entitlements: $entitlements,
            offered: $plan->is_active,
        );
    }
}
