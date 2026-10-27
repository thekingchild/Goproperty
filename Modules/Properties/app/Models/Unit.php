<?php

declare(strict_types=1);

namespace Modules\Properties\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Properties\Database\Factories\UnitFactory;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;

/**
 * A flat, self-contain, shop or office in a building (DATABASE_SCHEMA.md
 * § 3 `units`, ARCHITECTURE.md § 6.2): organization-scoped, its label
 * unique in the building. Written only through
 * Modules\Properties\Units\Units and CreateUnitsInBulk, which check
 * UnitPolicy and audit every change. Rent and service charge are kobo
 * through MoneyCast (only App\Support\Money is accepted). Archiving is a
 * soft delete, so the unit stops counting towards the GoProperty bill
 * (BILLING_PAYMENTS.md § 1.4); `occupied` and `reserved` are set by
 * Leasing through Contracts\UnitDirectory. Other modules read units
 * through that contract.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $property_id
 * @property string $unit_label unique in the building, archived units included
 * @property UnitType $unit_type
 * @property int|null $bedrooms
 * @property int|null $bathrooms
 * @property int|null $size_sqm
 * @property string|null $floor
 * @property Money $base_rent_kobo yearly asking rent
 * @property Money $service_charge_kobo yearly service charge
 * @property UnitStatus $status
 * @property string|null $description
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Property|null $property
 */
final class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['unit_label', 'unit_type', 'bedrooms', 'bathrooms', 'size_sqm', 'floor', 'base_rent_kobo', 'service_charge_kobo', 'description'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'vacant', 'base_rent_kobo' => 0, 'service_charge_kobo' => 0];

    /**
     * The building, archived ones included (an archived building keeps its
     * units, read-only).
     *
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
            'status' => UnitStatus::class,
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'size_sqm' => 'integer',
            'base_rent_kobo' => MoneyCast::class,
            'service_charge_kobo' => MoneyCast::class,
        ];
    }
}
