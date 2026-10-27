<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Casts\MoneyCast;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SaasBilling\Enums\BillingTransferStatus;

/**
 * An offer to hand an organization's GoProperty bill to another
 * organization (DATABASE_SCHEMA.md § 1, BILLING_PAYMENTS.md § 7).
 *
 * Organization-scoped to the offering organization (`organization_id`:
 * the current payer, whose bill moves and who may withdraw the offer).
 * The receiving organization (`to_organization_id`) never queries this
 * model in its own context: Transfers\IncomingTransfers reads the ids of
 * the rows addressed to it, then loads each one inside the offering
 * organization's context (P02 decision 2, as P01-04 does for invitation
 * links). No scope is removed anywhere.
 *
 * Every change goes through Transfers\BillingTransferService (AGENT.md § 3
 * rule 5); nothing is mass-assignable.
 *
 * @property int $id
 * @property int $organization_id the offering organization (current payer)
 * @property int $to_organization_id the organization asked to take the bill over
 * @property int|null $subscription_id no foreign key until P06-01
 * @property BillingTransferStatus $status
 * @property string|null $plan_key the plan the bill was worked out on, recorded on acceptance
 * @property int|null $billable_units the units counted, recorded on acceptance
 * @property Money|null $monthly_amount_kobo the monthly amount (before VAT) accepted
 * @property int|null $initiated_by_user_id
 * @property int|null $responded_by_user_id
 * @property CarbonImmutable|null $responded_at accepted or declined
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $completed_at set by the payer switch (P06-06), never before
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Organization $toOrganization
 * @property-read User|null $initiatedBy
 * @property-read User|null $respondedBy
 */
final class BillingTransferRequest extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BillingTransferStatus::class,
            'billable_units' => 'integer',
            'monthly_amount_kobo' => MoneyCast::class,
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /** Past its 14 days (whether or not the expiry has been recorded yet). */
    public function isPastExpiry(): bool
    {
        return $this->expires_at === null || ! $this->expires_at->isFuture();
    }

    /** Pending and in date: it can still be accepted, declined or withdrawn. */
    public function isOpen(): bool
    {
        return $this->status === BillingTransferStatus::Pending && ! $this->isPastExpiry();
    }
}
