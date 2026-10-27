<?php

declare(strict_types=1);

namespace Modules\Properties\Database\Factories;

use App\Support\NigerianStates;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * Buildings for tests and demo data. Like every organization-owned model,
 * make them inside an organization (OrganizationContext::run). The slug
 * follows the name with a random suffix; pages use the real writer
 * (Buildings\Properties), which picks the next free slug.
 *
 * @extends Factory<Property>
 */
final class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->randomElement(['Palm', 'Unity', 'Royal', 'Harmony', 'Cedar', 'Admiralty']).' '.fake()->randomElement(['Court', 'Gardens', 'Towers', 'Estate', 'Residence']);

        return [
            'name' => $name,
            'property_type' => PropertyType::BlockOfFlats,
            'address' => fake()->streetAddress(),
            'state' => fake()->randomElement(NigerianStates::ALL),
            'amenities' => ['Borehole', 'Security'],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Property $property): void {
            if (! is_string($property->getAttribute('slug')) || $property->getAttribute('slug') === '') {
                $property->slug = Str::slug($property->name).'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['status' => PropertyStatus::Archived]);
    }

    public function ownedBy(OwnerProfile $owner): self
    {
        return $this->state(fn (): array => ['owner_profile_id' => $owner->id]);
    }
}
