<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * A building as other modules see it (PropertyDirectory): plain values, no
 * model. `type` is a `properties.property_type` value and `typeLabel` its
 * label; `ownerName` is the owner profile's display name, if the building
 * has one.
 */
final readonly class PropertySummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $name,
        public string $slug,
        public string $type,
        public string $typeLabel,
        public string $address,
        public string $state,
        public bool $archived,
        public ?int $ownerProfileId,
        public ?string $ownerName,
    ) {}
}
