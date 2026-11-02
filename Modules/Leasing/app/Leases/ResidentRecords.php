<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Modules\Leasing\Contracts\BuildingResidents;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;

/** Answers BuildingResidents from the organization's active leases (P07-01). */
final readonly class ResidentRecords implements BuildingResidents
{
    public function tenantsIn(array $propertyIds): array
    {
        $residents = array_fill_keys(array_values(array_unique($propertyIds)), []);

        if ($residents === []) {
            return [];
        }

        $rows = Lease::query()
            ->whereIn('property_id', array_keys($residents))
            ->where('status', LeaseStatus::Active)
            ->distinct()
            ->orderBy('property_id')
            ->orderBy('tenant_profile_id')
            ->get(['property_id', 'tenant_profile_id']);

        foreach ($rows as $row) {
            $residents[(int) $row->property_id][] = (int) $row->tenant_profile_id;
        }

        return $residents;
    }
}
