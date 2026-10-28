<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use DateTimeInterface;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;

/**
 * Who a unit's utility bill goes to (P03-06b; ARCHITECTURE.md § 6.4 "How
 * utility bills are built"): the tenant of the unit's lease that covers
 * the day — the period's first day for a flat utility (billed ahead), its
 * last day for a metered one (billed after the readings). Only leases that
 * were activated and ran their course count: `active`, `renewed` (it runs
 * to its end) and `expired`. Never a draft (not let yet), never a lease
 * ended early (`terminated`: its planned end date is not when it ended), so
 * a period is never billed to a tenant who was not there. Two leases
 * covering the day (a renewal starting inside the old one's term): the one
 * that starts last.
 *
 * Read through the Leasing module's contract (Modules\Leasing\Contracts\
 * TenantLeases: the building's tenants, then each tenant's leases), never
 * its tables (ARCHITECTURE.md § 8 rule 3), once per building per run: make
 * one of these per run (Bills\UtilityBillGenerator does), in the current
 * organization.
 */
final class UnitTenancies
{
    /** `leases.status` values of leases that were let and ran (LeaseSummary::$status). */
    private const array COUNTED = ['active', 'renewed', 'expired'];

    /** @var array<int, list<LeaseSummary>> building id => its leases, latest start first */
    private array $byBuilding = [];

    public function __construct(private readonly TenantLeases $leases) {}

    /** The unit's lease covering the day, or null when nobody held the unit on a lease then. */
    public function on(int $propertyId, int $unitId, DateTimeInterface $day): ?LeaseSummary
    {
        $date = $day->format('Y-m-d');

        foreach ($this->leasesIn($propertyId) as $lease) {
            if ($lease->unitId === $unitId
                && $lease->contractNumber !== null
                && in_array($lease->status, self::COUNTED, true)
                && $lease->startDate->toDateString() <= $date
                && $lease->endDate->toDateString() >= $date) {
                return $lease;
            }
        }

        return null;
    }

    /** @return list<LeaseSummary> */
    private function leasesIn(int $propertyId): array
    {
        if (! array_key_exists($propertyId, $this->byBuilding)) {
            $tenantIds = array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                $this->leases->tenantIdsQuery([$propertyId])->pluck('tenant_profile_id')->all(),
            )));
            $leases = [];

            foreach ($tenantIds as $tenantId) {
                foreach ($this->leases->forTenant($tenantId) as $lease) {
                    if ($lease->propertyId === $propertyId) {
                        $leases[] = $lease;
                    }
                }
            }

            usort($leases, static fn (LeaseSummary $a, LeaseSummary $b): int => [$b->startDate->toDateString(), $b->id] <=> [$a->startDate->toDateString(), $a->id]);
            $this->byBuilding[$propertyId] = $leases;
        }

        return $this->byBuilding[$propertyId];
    }
}
