<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An organization's GoProperty subscription (P06-01b; BILLING_PAYMENTS.md
 * § 2, DATABASE_SCHEMA.md § 2 `subscriptions`): one per organization, kept
 * through cancellation. Organization-scoped; changed only by
 * App\Subscriptions\SubscriptionService (AGENT.md § 3 rule 5); never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property BillingCycle $billing_cycle
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $past_due_at
 * @property CarbonImmutable|null $grace_started_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $ended_at
 * @property bool $organization_suspended
 * @property int|null $payer_organization_id
 * @property int|null $last_billed_units
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubscriptionPlan $plan
 * @property-read Organization $organization
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Subscriptions are never deleted; they are cancelled.'));
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * The day whose plan version applies (P06-subtasks default 4): the
     * current billing period's first day, else today (in trial).
     */
    public function termsDate(?CarbonImmutable $today = null): CarbonImmutable
    {
        return $this->current_period_start ?? ($today ?? CarbonImmutable::now('Africa/Lagos'));
    }

    /** Who pays (P06-02): the payer organization when one is set, else the organization itself. */
    public function payerId(): int
    {
        return $this->payer_organization_id ?? $this->organization_id;
    }

    /** Whole days of trial left (0 on the last day or after). */
    public function trialDaysLeft(?CarbonImmutable $now = null): int
    {
        if ($this->status !== SubscriptionStatus::Trialing || $this->trial_ends_at === null) {
            return 0;
        }

        $now ??= CarbonImmutable::now();

        return max(0, (int) ceil($now->diffInSeconds($this->trial_ends_at, false) / 86_400));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'cancel_at_period_end' => 'boolean',
            'status_changed_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'past_due_at' => 'immutable_datetime',
            'grace_started_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'organization_suspended' => 'boolean',
            'payer_organization_id' => 'integer',
            'last_billed_units' => 'integer',
        ];
    }
}
