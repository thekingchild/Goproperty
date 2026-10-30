<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A plan's priced terms and features from a Lagos date (P06-01a;
 * BILLING_PAYMENTS.md § 1.3). Never deleted; once in effect never changed
 * (App\Platform\Plans\PlanEditor only fills in `cancelled_at` of a
 * scheduled one).
 *
 * @property int $id
 * @property int $plan_id
 * @property int $version
 * @property int $base_price_kobo
 * @property int $included_units
 * @property int|null $unit_price_kobo
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $notice_given_at
 * @property string|null $reason
 * @property int|null $created_by_staff_id
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by_staff_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SubscriptionPlan $plan
 */
class PlanVersion extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Plan versions are never deleted.'));
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'created_by_staff_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'base_price_kobo' => 'integer',
            'included_units' => 'integer',
            'unit_price_kobo' => 'integer',
            'effective_from' => 'immutable_date',
            'notice_given_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
