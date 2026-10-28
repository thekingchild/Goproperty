<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Billing\Enums\ChargingMode;
use Modules\Billing\Enums\MeterUnit;
use Modules\Leasing\Contracts\BillingCycle;

/**
 * A utility the organization charges for (DATABASE_SCHEMA.md § 4
 * `utility_types`, ARCHITECTURE.md § 6.4; P03-06a): flat (a fixed amount
 * each cycle) or metered (a rate per unit of measure times the
 * consumption), with a default amount and cycle that units' settings may
 * override. Organization-scoped; written only by Utilities\UtilityTypes;
 * archived (`active` false), never deleted. Nothing is mass assignable.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $code
 * @property ChargingMode $charging_mode
 * @property MeterUnit|null $unit_of_measure
 * @property Money $default_amount_kobo
 * @property BillingCycle $default_cycle
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class UtilityType extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Utility types are archived, never deleted.'));
    }

    /** @return HasMany<UnitUtilitySetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(UnitUtilitySetting::class);
    }

    public function isMetered(): bool
    {
        return $this->charging_mode === ChargingMode::Metered;
    }

    /** "₦25,000 every month" or "₦250 per kWh". */
    public function describeAmount(?Money $amount = null): string
    {
        $amount ??= $this->default_amount_kobo;

        return $this->isMetered()
            ? __(':amount per :unit', ['amount' => $amount->format(), 'unit' => __(($this->unit_of_measure ?? MeterUnit::Unit)->label())])
            : $amount->format();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'charging_mode' => ChargingMode::class,
            'unit_of_measure' => MeterUnit::class,
            'default_amount_kobo' => MoneyCast::class,
            'default_cycle' => BillingCycle::class,
            'active' => 'boolean',
        ];
    }
}
