<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Plans\Feature;
use App\Plans\Features;
use App\Plans\PlanChanges;
use App\Plans\PlanUsage;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * What Settings → Subscription and the workspace banner show about the
 * current organization's subscription (P06-01b): its plan at today's terms,
 * the units counted, a scheduled change of the plan's terms with its lines
 * (BILLING_PAYMENTS.md § 1.3, the 30 days' notice), and staff users used.
 * Reads only.
 */
final readonly class SubscriptionOverview
{
    public function __construct(
        private OrganizationContext $context,
        private Features $features,
        private PlanCatalogue $plans,
        private PlanUsage $usage,
    ) {}

    public function subscription(): ?Subscription
    {
        return Subscription::query()->first();
    }

    public function plan(): ?Plan
    {
        return $this->features->planFor($this->context->get());
    }

    /** Billable units today (Properties' count), 0 when that module is off. */
    public function units(): int
    {
        return app()->bound(UnitDirectory::class) ? app(UnitDirectory::class)->countBillable() : 0;
    }

    /**
     * The plan's next version, if one is scheduled and not yet in effect for
     * this subscription, with what changes.
     *
     * @return array{date: CarbonImmutable, plan: Plan, lines: list<string>}|null
     */
    public function scheduledChange(): ?array
    {
        $subscription = $this->subscription();
        $current = $this->plan();

        if ($subscription === null || $current === null || ! $subscription->status->isLive()) {
            return null;
        }

        $version = $this->plans->scheduled($subscription->plan_id, $subscription->termsDate());

        if (! $version instanceof PlanVersion) {
            return null;
        }

        $next = $this->plans->withVersion($version);

        return [
            'date' => CarbonImmutable::parse($version->effective_from->toDateString(), 'Africa/Lagos'),
            'plan' => $next,
            'lines' => PlanChanges::lines($current, $next),
        ];
    }

    public function staffUsed(): int
    {
        return $this->usage->count(Feature::StaffUsers, $this->context->get());
    }

    public function staffLimit(): ?int
    {
        return $this->features->limit(Feature::StaffUsers);
    }
}
