<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Casts\MoneyCast;
use App\Media\HasOrganizationMedia;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Billing\Utilities\MeterValue;
use Modules\Billing\Utilities\MeterValueCast;
use Spatie\MediaLibrary\HasMedia;

/**
 * A meter reading of a unit's metered utility (DATABASE_SCHEMA.md § 4
 * `utility_readings`, ARCHITECTURE.md § 6.4; P03-06a): the date, the
 * previous and current values and the consumption between them, whether
 * the meter was replaced, the rate per unit of measure when it was taken,
 * who recorded it and, once billed (P03-06b), its bill. An optional photo
 * of the meter is evidence, kept in this reading's `meter_photos` media
 * collection (never in `photos`; AGENT.md § 3 rule 7).
 *
 * Organization-scoped; written only by Utilities\MeterReadings. A reading
 * once billed never changes; the latest one not yet billed may be
 * corrected, or removed (a soft delete: `live` becomes null, the photo
 * stays). Nothing is mass assignable.
 *
 * Live, building-linked record: a management handover (P03-14) moves it
 * with its unit's setting.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $unit_utility_setting_id
 * @property CarbonImmutable $reading_date
 * @property MeterValue $previous_value
 * @property MeterValue $current_value
 * @property MeterValue $consumption
 * @property bool $meter_replaced
 * @property Money $rate_kobo
 * @property string|null $note
 * @property int|null $recorded_by_user_id
 * @property int|null $bill_id
 * @property bool|null $live
 * @property int|null $removed_by_user_id
 * @property string|null $removal_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read UnitUtilitySetting $setting
 */
final class UtilityReading extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia, SoftDeletes;

    /** The meter photo's media collection (evidence; config `media.collections`, added by the provider). */
    public const string PHOTO_COLLECTION = 'meter_photos';

    /** @var array<string, mixed> */
    protected $attributes = ['meter_replaced' => false, 'live' => true];

    protected static function booted(): void
    {
        self::updating(static function (self $reading): void {
            if ($reading->getRawOriginal('bill_id') !== null) {
                throw new LogicException('A billed meter reading never changes.');
            }
        });
        self::forceDeleting(static fn (): never => throw new LogicException('Meter readings are removed (kept with their photo), never deleted for good.'));
    }

    /** @return BelongsTo<UnitUtilitySetting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(UnitUtilitySetting::class, 'unit_utility_setting_id');
    }

    public function isBilled(): bool
    {
        return $this->bill_id !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reading_date' => 'immutable_date',
            'previous_value' => MeterValueCast::class,
            'current_value' => MeterValueCast::class,
            'consumption' => MeterValueCast::class,
            'meter_replaced' => 'boolean',
            'rate_kobo' => MoneyCast::class,
            'bill_id' => 'integer',
            'live' => 'boolean',
        ];
    }
}
