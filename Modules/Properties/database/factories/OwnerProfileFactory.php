<?php

declare(strict_types=1);

namespace Modules\Properties\Database\Factories;

use App\Support\NigerianStates;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Models\OwnerProfile;

/**
 * Owner profiles for tests and demo data. Like every organization-owned
 * model, make them inside an organization (OrganizationContext::run).
 *
 * @extends Factory<OwnerProfile>
 */
final class OwnerProfileFactory extends Factory
{
    protected $model = OwnerProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => OwnerType::Person,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+234802'.fake()->unique()->numerify('#######'), // never 0809 111…, a number tests search for
            'address' => fake()->streetAddress(),
            'state' => fake()->randomElement(NigerianStates::ALL),
            'id_type' => OwnerIdType::None,
        ];
    }

    public function company(): self
    {
        return $this->state(fn (): array => [
            'type' => OwnerType::Company,
            'first_name' => null,
            'last_name' => null,
            'company_name' => fake()->company(),
        ]);
    }

    public function withNin(string $nin = '12345678901'): self
    {
        return $this->state(fn (): array => ['id_type' => OwnerIdType::Nin, 'id_value' => $nin]);
    }
}
