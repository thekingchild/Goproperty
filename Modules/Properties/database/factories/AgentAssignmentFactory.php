<?php

declare(strict_types=1);

namespace Modules\Properties\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\Property;

/**
 * Agent assignments for tests. Make them inside an organization
 * (OrganizationContext::run); `of($user, $property)` links an existing
 * agent and building. Pages use the real writer (Assignments\AgentAssignments).
 *
 * @extends Factory<AgentAssignment>
 */
final class AgentAssignmentFactory extends Factory
{
    protected $model = AgentAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'property_id' => Property::factory(),
        ];
    }

    public function of(User $user, Property $property): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id, 'property_id' => $property->id]);
    }
}
