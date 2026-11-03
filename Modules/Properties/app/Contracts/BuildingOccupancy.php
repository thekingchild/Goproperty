<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * Each building's units by status now, for the customer dashboards
 * (P08-01, the Reports module): never the Properties module's tables
 * (ARCHITECTURE.md § 8 rule 3). Active buildings only (archived ones and
 * those removed by an import rollback are left out), archived units never
 * counted, as on the building page (UnitDirectory::countsForProperty).
 *
 * `$propertyIds` narrows to those buildings (an assigned agent's, from
 * Contracts\BuildingAccess, or one picked in a filter); null means every
 * active building. Answers for the current organization only; no
 * permission check. Bound in PropertiesServiceProvider.
 */
interface BuildingOccupancy
{
    /**
     * Active buildings by name, each with its units by status (a building
     * with no units has zero counts). Two queries, however many buildings.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<BuildingUnits>
     */
    public function active(?array $propertyIds = null): array;
}
