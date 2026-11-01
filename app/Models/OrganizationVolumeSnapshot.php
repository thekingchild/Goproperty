<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionTier;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What the daily Transaction Tier run measured for one organization on one
 * Lagos day (P06-09; DATABASE_SCHEMA.md § 9 `organization_volume_snapshots`):
 * the rolling 12 months, the 12-month forecast, the largest payment, the
 * calendar month so far, and the limits that applied (null = no cap).
 * Organization-scoped. Written only by App\Compliance\Monitoring\TierMonitor.
 *
 * @property int $id
 * @property int $organization_id
 * @property CarbonImmutable $date
 * @property TransactionTier $tier
 * @property int $rolling_12m_volume_kobo
 * @property int $forecast_12m_kobo
 * @property int $largest_payment_kobo
 * @property int $month_volume_kobo
 * @property int|null $annual_limit_kobo
 * @property int|null $single_payment_limit_kobo
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OrganizationVolumeSnapshot extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'tier' => TransactionTier::class,
            'rolling_12m_volume_kobo' => 'integer',
            'forecast_12m_kobo' => 'integer',
            'largest_payment_kobo' => 'integer',
            'month_volume_kobo' => 'integer',
            'annual_limit_kobo' => 'integer',
            'single_payment_limit_kobo' => 'integer',
        ];
    }
}
