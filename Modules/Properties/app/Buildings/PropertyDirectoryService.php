<?php

declare(strict_types=1);

namespace Modules\Properties\Buildings;

use App\Models\User;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Models\Property;

/** The Properties module's answer to Contracts\PropertyDirectory, through the organization scope. */
final readonly class PropertyDirectoryService implements PropertyDirectory
{
    public function __construct(private AgentNarrowing $narrowing) {}

    public function find(int $propertyId): ?PropertySummary
    {
        $property = Property::query()->with('owner')->find($propertyId);

        return $property instanceof Property ? self::summary($property) : null;
    }

    public function findMany(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return Property::query()->with('owner')->whereKey($propertyIds)->get()
            ->mapWithKeys(fn (Property $property): array => [$property->id => self::summary($property)])
            ->all();
    }

    public function isActive(int $propertyId): bool
    {
        return Property::query()->whereKey($propertyId)->where('status', PropertyStatus::Active)->exists();
    }

    public function activeOptions(?User $for = null): array
    {
        $query = $for === null ? Property::query() : $this->narrowing->buildings(Property::query(), $for);

        /** @var array<int, string> */
        return $query->where('status', PropertyStatus::Active)->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    public function countActive(): int
    {
        return Property::query()->where('status', PropertyStatus::Active)->count();
    }

    private static function summary(Property $property): PropertySummary
    {
        return new PropertySummary(
            id: $property->id,
            organizationId: $property->organization_id,
            name: $property->name,
            slug: $property->slug,
            type: $property->property_type->value,
            typeLabel: $property->property_type->label(),
            address: $property->address,
            state: $property->state,
            archived: $property->isArchived(),
            ownerProfileId: $property->owner_profile_id,
            ownerName: $property->owner?->display_name,
        );
    }
}
