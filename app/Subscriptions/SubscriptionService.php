<?php

declare(strict_types=1);

namespace App\Subscriptions;

use App\Enums\BillingCycle;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Marketing\Plan;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\PlanUsage;
use App\Subscriptions\Events\SubscriptionStatusChanged;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `subscriptions` (P06-01b; AGENT.md § 3 rule 5,
 * BILLING_PAYMENTS.md § 2; docs/plans/P06-subtasks.md defaults 8–12).
 * Works on the current organization's subscription (OrganizationContext).
 *
 * - start(): the signup wizard's plan step starts a `trialing` subscription;
 *   choosing again during the trial changes the plan and keeps the end.
 * - changePlan(): at once, at the new plan's current terms (§ 3: the current
 *   invoice is untouched). A customer may not move to a plan whose limits
 *   it is over, nor while its organization is read-only.
 * - Lifecycle: endTrial(), markPastDue(), startGrace(), suspend(),
 *   activate() (paid), cancel(). Each follows SubscriptionStatus::next(),
 *   is one audit entry (log `subscriptions`) and one
 *   SubscriptionStatusChanged event after the commit.
 * - `suspended` and `cancelled` make the organization read-only through its
 *   status (ARCHITECTURE.md § 4), recording the status to return to;
 *   `active` again restores it, only when the subscription was what
 *   suspended it (`organization_suspended`): a staff suspension is lifted
 *   by staff (App\Platform\Customers\OrganizationSuspension).
 */
final readonly class SubscriptionService
{
    public const string LOG = 'subscriptions';

    public function __construct(
        private OrganizationContext $context,
        private PlanUsage $usage,
    ) {}

    public function current(): ?Subscription
    {
        return Subscription::query()->first();
    }

    /** Starts the trial on $plan, or changes the plan of a trial already started. */
    public function start(?User $by, Plan $plan, CarbonImmutable $trialEndsAt, BillingCycle $cycle = BillingCycle::Monthly): Subscription
    {
        return DB::transaction(function () use ($by, $plan, $trialEndsAt, $cycle): Subscription {
            $this->lockOrganization();
            $existing = Subscription::query()->lockForUpdate()->first();

            if ($existing instanceof Subscription) {
                return $existing->plan_id === $plan->id && $existing->billing_cycle === $cycle
                    ? $existing
                    : $this->switchPlan($existing, $plan, $cycle, $by);
            }

            $subscription = new Subscription;
            $subscription->forceFill([
                'organization_id' => $this->context->id(),
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => $trialEndsAt,
                'status_changed_at' => now(),
            ])->save();

            $this->audit('subscription.started', $subscription, $by, "Started a free trial of {$plan->name}", [
                'plan' => $plan->key,
                'plan_version' => $plan->version,
                'billing_cycle' => $cycle->value,
                'trial_ends_at' => $trialEndsAt->toIso8601String(),
            ]);

            SubscriptionStatusChanged::dispatch($subscription->organization_id, $subscription->id, null, SubscriptionStatus::Trialing);

            return $subscription;
        }, 3);
    }

    /**
     * Moves to another plan (or billing cycle) at once. A customer ($by a
     * User) is refused while the organization is read-only, and when it
     * uses more of a limit than the new plan includes; staff are not.
     *
     * @throws SubscriptionChangeRefused
     */
    public function changePlan(User|PlatformStaff $by, Plan $plan, ?BillingCycle $cycle = null): Subscription
    {
        return DB::transaction(function () use ($by, $plan, $cycle): Subscription {
            $organization = $this->lockOrganization();
            $subscription = $this->locked();
            $cycle ??= $subscription->billing_cycle;

            if ($subscription->plan_id === $plan->id && $subscription->billing_cycle === $cycle) {
                throw new SubscriptionChangeRefused("{$organization->name} is already on {$plan->name}.");
            }

            if ($by instanceof User) {
                if ($organization->status->blocksWrites() || ! $subscription->status->isLive()) {
                    throw new SubscriptionChangeRefused("{$organization->name} is {$organization->status->value}: only paying the subscription is possible until it is active again.");
                }

                if (! $plan->offered) {
                    throw new SubscriptionChangeRefused("{$plan->name} is no longer offered.");
                }

                if ($plan->custom) {
                    throw new SubscriptionChangeRefused("{$plan->name} is priced for each customer: contact GoProperty to move to it.");
                }

                $staffLimit = $plan->feature(Feature::StaffUsers)->limit;
                $staff = $this->usage->count(Feature::StaffUsers, $organization);

                if ($staffLimit !== null && $staff > $staffLimit) {
                    throw new SubscriptionChangeRefused("{$plan->name} includes {$staffLimit} staff users and {$organization->name} has {$staff} (members and invitations still waiting). Remove ".($staff - $staffLimit).' first.');
                }
            }

            return $this->switchPlan($subscription, $plan, $cycle, $by);
        }, 3);
    }

    /** trialing → active: the trial is over and the first billing period starts (P06-02 invoices it). */
    public function endTrial(?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();

        return $this->move(SubscriptionStatus::Active, null, 'The free trial ended', function (Subscription $subscription) use ($now): void {
            if ($subscription->status !== SubscriptionStatus::Trialing) {
                throw new SubscriptionChangeRefused('The subscription is not in its trial.');
            }

            if ($subscription->trial_ends_at !== null && $subscription->trial_ends_at->isAfter($now)) {
                throw new SubscriptionChangeRefused('The trial has not ended yet.');
            }

            $start = ($subscription->trial_ends_at ?? $now)->setTimezone('Africa/Lagos')->startOfDay();
            $subscription->forceFill([
                'current_period_start' => $start->toDateString(),
                'current_period_end' => null,
                'activated_at' => $now,
            ]);
        });
    }

    /** active → past_due: an invoice is open past its due date (P06-05). */
    public function markPastDue(?string $reason = null): Subscription
    {
        return $this->move(SubscriptionStatus::PastDue, null, $reason ?? 'An invoice is unpaid past its due date', static function (Subscription $subscription): void {
            $subscription->past_due_at = now();
        });
    }

    /** past_due → grace: the final days before suspension (P06-05). */
    public function startGrace(?string $reason = null): Subscription
    {
        return $this->move(SubscriptionStatus::Grace, null, $reason ?? 'The grace period started', static function (Subscription $subscription): void {
            $subscription->grace_started_at = now();
        });
    }

    /** grace → suspended: the organization becomes read-only (P06-05). */
    public function suspend(User|PlatformStaff|null $by = null, ?string $reason = null): Subscription
    {
        return $this->move(SubscriptionStatus::Suspended, $by, $reason ?? 'Unpaid beyond the grace period', function (Subscription $subscription, Organization $organization): void {
            $subscription->suspended_at = now();
            $this->closeOrganization($subscription, $organization, OrganizationStatus::Suspended);
        });
    }

    /** Paid (or reactivated by staff): back to `active` from past_due, grace, suspended or cancelled. */
    public function activate(User|PlatformStaff|null $by = null, ?string $reason = null): Subscription
    {
        return $this->move(SubscriptionStatus::Active, $by, $reason ?? 'Paid', function (Subscription $subscription, Organization $organization): void {
            if ($subscription->status === SubscriptionStatus::Trialing) {
                throw new SubscriptionChangeRefused('A trial becomes active when it ends.');
            }

            $subscription->forceFill([
                'activated_at' => $subscription->activated_at ?? now(),
                'past_due_at' => null,
                'grace_started_at' => null,
                'suspended_at' => null,
                'ended_at' => null,
                'cancel_at_period_end' => false,
            ]);
            $this->reopenOrganization($subscription, $organization);
        });
    }

    /** Ends the subscription (the owner cancels, or non-payment); the organization is closed, its data kept. */
    public function cancel(User|PlatformStaff|null $by, string $reason): Subscription
    {
        return $this->move(SubscriptionStatus::Cancelled, $by, $reason, function (Subscription $subscription, Organization $organization): void {
            $subscription->ended_at = now();
            $this->closeOrganization($subscription, $organization, OrganizationStatus::Cancelled);
        });
    }

    /**
     * @param  callable(Subscription, Organization): void  $apply
     *
     * @throws SubscriptionChangeRefused
     */
    private function move(SubscriptionStatus $to, User|PlatformStaff|null $by, string $reason, callable $apply): Subscription
    {
        return DB::transaction(function () use ($to, $by, $reason, $apply): Subscription {
            $organization = $this->lockOrganization();
            $subscription = $this->locked();
            $from = $subscription->status;

            if (! $from->canMoveTo($to)) {
                throw new SubscriptionChangeRefused("A subscription that is {$from->label()} cannot become {$to->label()}.");
            }

            $organizationBefore = $organization->status;
            $apply($subscription, $organization);
            $subscription->forceFill(['status' => $to, 'status_changed_at' => now()])->save();

            $this->audit("subscription.{$to->value}", $subscription, $by, $reason, array_filter([
                'old' => ['status' => $from->value],
                'attributes' => ['status' => $to->value],
                'organization_status' => $organizationBefore !== $organization->status
                    ? ['from' => $organizationBefore->value, 'to' => $organization->status->value]
                    : null,
            ], static fn (mixed $value): bool => $value !== null));

            SubscriptionStatusChanged::dispatch($subscription->organization_id, $subscription->id, $from, $to);

            return $subscription;
        }, 3);
    }

    private function switchPlan(Subscription $subscription, Plan $plan, BillingCycle $cycle, User|PlatformStaff|null $by): Subscription
    {
        $before = ['plan_id' => $subscription->plan_id, 'billing_cycle' => $subscription->billing_cycle->value];
        $subscription->forceFill(['plan_id' => $plan->id, 'billing_cycle' => $cycle])->save();

        $this->audit('subscription.plan_changed', $subscription, $by, "Moved to {$plan->name}", [
            'old' => $before,
            'attributes' => ['plan_id' => $plan->id, 'billing_cycle' => $cycle->value],
            'plan' => $plan->key,
            'plan_version' => $plan->version,
        ]);

        return $subscription;
    }

    /** Suspends or closes the organization unless staff already did (then staff lift it). */
    private function closeOrganization(Subscription $subscription, Organization $organization, OrganizationStatus $to): void
    {
        $status = $organization->status;
        $ours = $subscription->organization_suspended && $status->blocksWrites();

        if (! $status->canBeSuspended() && ! $ours) {
            return;
        }

        $organization->forceFill([
            'status' => $to,
            'suspended_at' => $organization->suspended_at ?? now(),
            'status_before_suspension' => $ours ? $organization->status_before_suspension : $status,
        ]);
        $this->saveQuietly($organization);
        $subscription->organization_suspended = true;
    }

    private function reopenOrganization(Subscription $subscription, Organization $organization): void
    {
        if (! $subscription->organization_suspended) {
            return;
        }

        $subscription->organization_suspended = false;

        if (! $organization->status->blocksWrites()) {
            return;
        }

        $back = $organization->status_before_suspension;
        $organization->forceFill([
            'status' => $back !== null && $back->canBeSuspended() ? $back : OrganizationStatus::Active,
            'suspended_at' => null,
            'status_before_suspension' => null,
        ]);
        $this->saveQuietly($organization);
    }

    /** @throws SubscriptionChangeRefused */
    private function locked(): Subscription
    {
        return Subscription::query()->lockForUpdate()->first()
            ?? throw new SubscriptionChangeRefused("{$this->context->get()->name} has no subscription yet: an owner chooses a plan first.");
    }

    private function lockOrganization(): Organization
    {
        return Organization::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();
    }

    /** The subscription's audit entry says what happened to the organization; no second entry. */
    private function saveQuietly(Organization $organization): void
    {
        $organization->disableLogging();

        try {
            $organization->save();
        } finally {
            $organization->enableLogging();
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Subscription $subscription, ?Model $by, string $description, array $properties): void
    {
        $entry = activity(self::LOG)->event($event)->performedOn($subscription)->withProperties($properties + ['organization_id' => $subscription->organization_id]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
