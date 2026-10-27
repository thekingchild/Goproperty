<?php

declare(strict_types=1);

namespace Modules\Leasing\Lists;

use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The tenant, unit and building names of a batch of leases, read through
 * the Tenants and Properties contracts with one lookup each per batch (no
 * query per lease, ARCHITECTURE.md § 13), never by joining their tables
 * (§ 8 rule 3). A unit archived after its lease ended still shows its
 * building, and "Archived unit" for its label.
 */
final readonly class LeaseParties
{
    public function __construct(
        private TenantDirectory $tenants,
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
    ) {}

    /**
     * @param  iterable<Lease>  $leases
     * @return list<LeaseRow>
     */
    public function rows(iterable $leases): array
    {
        $leases = is_array($leases) ? array_values($leases) : iterator_to_array($leases, false);

        if ($leases === []) {
            return [];
        }

        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (Lease $lease): int => $lease->tenant_profile_id, $leases))));
        $units = $this->units->findMany(array_values(array_unique(array_map(static fn (Lease $lease): int => $lease->unit_id, $leases))));
        $buildings = $this->buildings->findMany(array_values(array_unique(array_map(static fn (Lease $lease): int => $lease->property_id, $leases))));
        $previous = [];
        $previousIds = array_values(array_unique(array_filter(array_map(static fn (Lease $lease): ?int => $lease->renewal_of_lease_id, $leases))));

        foreach ($previousIds === [] ? [] : Lease::query()->whereKey($previousIds)->get() as $renewed) {
            $previous[$renewed->id] = $renewed->reference();
        }

        return array_map(static fn (Lease $lease): LeaseRow => new LeaseRow(
            lease: $lease,
            tenantName: isset($tenants[$lease->tenant_profile_id]) ? $tenants[$lease->tenant_profile_id]->name : __('Unknown tenant'),
            unitLabel: isset($units[$lease->unit_id]) ? $units[$lease->unit_id]->label : __('Archived unit'),
            buildingName: isset($buildings[$lease->property_id]) ? $buildings[$lease->property_id]->name : __('Removed building'),
            renewalOfContract: $lease->renewal_of_lease_id === null ? null : ($previous[$lease->renewal_of_lease_id] ?? null),
        ), $leases);
    }
}
