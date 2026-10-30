<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\SaasBilling\Enums\UnitEventReason;

/**
 * A row of the metering ledger (P06-02; BILLING_PAYMENTS.md § 3 step 2,
 * DATABASE_SCHEMA.md § 2 `subscription_unit_events`): the units counted
 * for one billing period's invoice. Organization-scoped; written only by
 * Metering\SubscriptionMetering; never changed or deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $subscription_id
 * @property int|null $platform_invoice_id
 * @property int|null $plan_version_id
 * @property CarbonImmutable $effective_date
 * @property int $unit_count
 * @property int $included_units
 * @property int $counted_units
 * @property UnitEventReason $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class SubscriptionUnitEvent extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('The metering ledger is never changed.'));
        self::deleting(static fn (): never => throw new LogicException('The metering ledger is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subscription_id' => 'integer',
            'platform_invoice_id' => 'integer',
            'plan_version_id' => 'integer',
            'effective_date' => 'immutable_date',
            'unit_count' => 'integer',
            'included_units' => 'integer',
            'counted_units' => 'integer',
            'reason' => UnitEventReason::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
