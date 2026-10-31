<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The console's read model of an organization's GoProperty subscription
 * (P06-07a; BILLING_PAYMENTS.md § 2, DATABASE_SCHEMA.md § 2 `subscriptions`):
 * the `subscriptions` row across organizations, for the customers list and
 * the organization's page. Read-only: subscriptions change only through
 * App\Subscriptions\SubscriptionService (staff moves through
 * CustomerSubscriptions), never here.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property BillingCycle $billing_cycle
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $status_changed_at
 * @property bool $organization_suspended
 * @property int|null $payer_organization_id
 * @property int|null $last_billed_units
 * @property CarbonImmutable|null $created_at
 * @property-read SubscriptionPlan $plan
 */
final class CustomerSubscription extends Model
{
    protected $table = 'subscriptions';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('CustomerSubscription is a read model: subscriptions change only through SubscriptionService.'));
        self::deleting(fn (): never => throw new LogicException('Subscriptions are never deleted.'));
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** "1–30 November 2026", "From 16 November 2026" before its first invoice, or null in trial. */
    public function periodLabel(): ?string
    {
        $start = $this->current_period_start;
        $end = $this->current_period_end;

        if ($start === null) {
            return null;
        }

        if ($end === null) {
            return 'From '.$start->format('j F Y');
        }

        return $start->isSameMonth($end) ? $start->format('j').'–'.$end->format('j F Y') : $start->format('j F Y').' – '.$end->format('j F Y');
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
            'status_changed_at' => 'immutable_datetime',
            'organization_suspended' => 'boolean',
            'payer_organization_id' => 'integer',
            'last_billed_units' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
