<?php

declare(strict_types=1);

namespace Modules\Properties\Database\Factories;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

/**
 * Units for tests and demo data. Make them inside an organization
 * (OrganizationContext::run), for a building of that organization
 * (`in($property)`, or a new building is made). Pages use the real
 * writers (Units\Units, Units\CreateUnitsInBulk).
 *
 * @extends Factory<Unit>
 */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'unit_label' => 'Flat '.fake()->unique()->numberBetween(1, 999_999),
            'unit_type' => UnitType::Apartment,
            'bedrooms' => 2,
            'bathrooms' => 2,
            'base_rent_kobo' => Money::naira(1_500_000),
            'service_charge_kobo' => Money::naira(150_000),
        ];
    }

    public function in(Property $property): self
    {
        return $this->state(fn (): array => ['property_id' => $property->id]);
    }

    public function status(UnitStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
