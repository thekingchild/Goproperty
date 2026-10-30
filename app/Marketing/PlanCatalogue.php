<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Support\Money;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The plans and prices shown on the landing page (BILLING_PAYMENTS.md § 1.1),
 * from config/plans.php until the SaaS billing module provides the plans table.
 */
final class PlanCatalogue
{
    public function __construct(private readonly Config $config) {}

    /** @return list<Plan> */
    public function plans(): array
    {
        $plans = [];

        foreach ((array) $this->config->get('plans.plans', []) as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $plans[] = new Plan(
                (string) ($plan['key'] ?? ''),
                (string) ($plan['name'] ?? ''),
                (string) ($plan['audience'] ?? ''),
                Money::kobo((int) ($plan['base_kobo'] ?? 0)),
                (int) ($plan['included_units'] ?? 0),
                isset($plan['extra_unit_kobo']) && is_int($plan['extra_unit_kobo']) ? Money::kobo($plan['extra_unit_kobo']) : null,
                (bool) ($plan['featured'] ?? false),
                (bool) ($plan['custom'] ?? false),
                array_values(array_filter((array) ($plan['features'] ?? []), 'is_string')),
                max(0, (int) ($plan['sms_credits'] ?? 0)),
            );
        }

        return $plans;
    }

    public function starter(): Plan
    {
        return $this->plans()[0];
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

    public function trialDays(): int
    {
        $days = $this->config->get('plans.trial_days', 14);

        return is_numeric($days) ? (int) $days : 14;
    }

    /** The cheapest plan for a number of units, as the calculator picks it. */
    public function bestFor(int $units): Plan
    {
        $regular = array_values(array_filter($this->plans(), fn (Plan $plan): bool => ! $plan->custom));
        $custom = array_values(array_filter($this->plans(), fn (Plan $plan): bool => $plan->custom));

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
                $enterprise = ['key' => $plan->key, 'name' => $plan->name, 'base' => intdiv($plan->base->kobo, 100), 'incl' => $plan->includedUnits];

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
}
