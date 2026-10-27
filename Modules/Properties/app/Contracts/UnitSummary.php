<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

use App\Support\Money;

/**
 * A unit as other modules see it (UnitDirectory): plain values, no model.
 * `type` is a `units.unit_type` value and `status` a `units.status` value
 * (UnitDirectory's constants), each with its label. Rent and service
 * charge are the unit's yearly asking amounts; a lease keeps its own.
 */
final readonly class UnitSummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $propertyId,
        public string $propertyName,
        public bool $propertyArchived,
        public string $label,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public Money $baseRent,
        public Money $serviceCharge,
    ) {}
}
