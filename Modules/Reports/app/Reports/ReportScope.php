<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Models\User;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * Which buildings a report covers for a member (P08-01): every building of
 * the organization and its own money linked to no building (`propertyIds`
 * null), or only some buildings — the one picked in the filter, or an
 * assigned agent's (Properties' BuildingAccess; agents do not hold
 * `reports.view` by default, but a member narrowed to buildings never sees
 * more through a report). Money linked to no building (the organization's
 * own expenses) is only in a report of every building.
 */
final readonly class ReportScope
{
    /** @param  list<int>|null  $propertyIds */
    private function __construct(public ?array $propertyIds) {}

    public static function for(User $user, ReportFilters $filters, BuildingAccess $access): self
    {
        $limited = $access->limitedTo($user);

        if ($filters->buildingId !== null) {
            return new self($limited === null || in_array($filters->buildingId, $limited, true) ? [$filters->buildingId] : []);
        }

        return new self($limited);
    }

    /** Whether the member's narrowing lets them pick the filter's building. */
    public static function allows(User $user, ReportFilters $filters, BuildingAccess $access): bool
    {
        return $filters->buildingId === null || $access->allows($user, $filters->buildingId);
    }

    /** Whether the report covers every building and the money linked to none. */
    public function everything(): bool
    {
        return $this->propertyIds === null;
    }

    /** Whether money of this building (null: linked to none) is in the report. */
    public function covers(?int $propertyId): bool
    {
        if ($this->propertyIds === null) {
            return true;
        }

        return $propertyId !== null && in_array($propertyId, $this->propertyIds, true);
    }
}
