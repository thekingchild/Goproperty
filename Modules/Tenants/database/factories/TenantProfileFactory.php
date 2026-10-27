<?php

declare(strict_types=1);

namespace Modules\Tenants\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;

/**
 * Tenant profiles for tests and demo data. Like every organization-owned
 * model, make them inside an organization (OrganizationContext::run).
 *
 * @extends Factory<TenantProfile>
 */
final class TenantProfileFactory extends Factory
{
    protected $model = TenantProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+23481'.fake()->unique()->numerify('########'),
            'address' => fake()->streetAddress(),
            'occupation' => fake()->jobTitle(),
            'id_type' => TenantIdType::None,
        ];
    }

    public function withNin(string $nin = '12345678901'): self
    {
        return $this->state(fn (): array => ['id_type' => TenantIdType::Nin, 'id_value' => $nin]);
    }
}
