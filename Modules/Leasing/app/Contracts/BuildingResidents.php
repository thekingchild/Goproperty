<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * Who lives in a building now, for the Building Group (P07-01,
 * ARCHITECTURE.md § 6.8): the tenants holding an **active** lease of one of
 * its units. A draft (a unit reserved, nobody moved in) does not count; an
 * ended lease (expired, terminated, renewed) does not either — a renewed
 * lease's tenant still counts through the renewal, which is active. Leases
 * removed by an import rollback do not count. Answers for the current
 * organization only; no permission check. Bound in LeasingServiceProvider.
 */
interface BuildingResidents
{
    /**
     * @param  list<int>  $propertyIds
     * @return array<int, list<int>> building id => tenant profile ids, ascending; every asked-for building is a key
     */
    public function tenantsIn(array $propertyIds): array;
}
