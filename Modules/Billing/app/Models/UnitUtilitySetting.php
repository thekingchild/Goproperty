<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Billing\Enums\UtilityBillingHold;
use Modules\Billing\Utilities\MeterValue;
use Modules\Billing\Utilities\MeterValueCast;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * A utility a unit is charged (DATABASE_SCHEMA.md § 4
 * `unit_utility_settings`, ARCHITECTURE.md § 6.4; P03-06a): one row per
 * unit and utility type (unique), switched on or off (`active`), with an
 * optional amount and cycle overriding the type's defaults, the date it
 * applies from and, for a metered utility, the meter's starting reading.
 *
 * The unit and its building belong to the Properties module: the row keeps
 * their ids (the building's too, for agent narrowing) and reads them
 * through Modules\Properties\Contracts\UnitDirectory, never their models.
 * Organization-scoped; written only by Utilities\UnitUtilitySettings;
 * switched off, never deleted. Nothing is mass assignable.
 *
 * Bills\UtilityBillGenerator keeps where its billing stands
 * (`next_billing_date`, `billing_hold`, `billing_hold_since`; P03-06b).
 *
 * Live, building-linked record: a management handover (P03-14) moves it
 * with its building (BILLING_PAYMENTS.md § 7).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $property_id
 * @property int $unit_id
 * @property int $utility_type_id
 * @property Money|null $amount_kobo
 * @property BillingCycle|null $cycle
 * @property bool $active
 * @property CarbonImmutable $effective_from
 * @property MeterValue|null $starting_reading
 * @property CarbonImmutable|null $next_billing_date the next period to bill (null: from effective_from; P03-06b)
 * @property UtilityBillingHold|null $billing_hold why it is not being billed (P03-06b)
 * @property CarbonImmutable|null $billing_hold_since the first period start the hold applies to
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read UtilityType $utilityType
 */
final class UnitUtilitySetting extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A unit\'s utility is switched off, never deleted.'));
    }

    /** @return BelongsTo<UtilityType, $this> */
    public function utilityType(): BelongsTo
    {
        return $this->belongsTo(UtilityType::class);
    }

    /** @return HasMany<UtilityReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(UtilityReading::class);
    }

    /** The amount charged: this unit's override, else the type's default (per cycle, or per unit of measure when metered). */
    public function amount(): Money
    {
        return $this->amount_kobo ?? $this->utilityType->default_amount_kobo;
    }

    /** The billing cycle: this unit's override, else the type's default. */
    public function billingCycle(): BillingCycle
    {
        return $this->cycle ?? $this->utilityType->default_cycle;
    }

    public function isMetered(): bool
    {
        return $this->utilityType->isMetered();
    }

    /** Whether readings may be recorded: switched on, metered, and the type not archived. */
    public function takesReadings(): bool
    {
        return $this->active && $this->utilityType->active && $this->isMetered();
    }

    /** The first reading's previous value when no reading was kept yet. */
    public function startingReading(): MeterValue
    {
        return $this->starting_reading ?? MeterValue::zero();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'cycle' => BillingCycle::class,
            'active' => 'boolean',
            'effective_from' => 'immutable_date',
            'starting_reading' => MeterValueCast::class,
            'next_billing_date' => 'immutable_date',
            'billing_hold' => UtilityBillingHold::class,
            'billing_hold_since' => 'immutable_date',
            'import_batch_id' => 'integer',
        ];
    }
}
