<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * How other modules read buildings (ARCHITECTURE.md § 6.2, § 8 rule 3):
 * never the Properties module's models or tables. Answers for the current
 * organization only (OrganizationContext; without one it throws
 * App\Tenancy\MissingOrganizationContext), so another organization's
 * building is simply not found. Buildings removed by an import rollback
 * are not found either; archived ones are, with `archived` true.
 *
 * Read-only, and no permission check: the caller checks its own policy for
 * the user it acts for. Bound in PropertiesServiceProvider.
 */
interface PropertyDirectory
{
    /** The building, or null when the current organization has no such building. */
    public function find(int $propertyId): ?PropertySummary;

    /**
     * The buildings of these ids that the current organization has; unknown
     * ids are left out.
     *
     * @param  list<int>  $propertyIds
     * @return array<int, PropertySummary> by id
     */
    public function findMany(array $propertyIds): array;

    /** Whether the building exists here and is not archived (new units, leases, tickets need one). */
    public function isActive(int $propertyId): bool;

    /**
     * Active buildings for a picker, by name.
     *
     * @return array<int, string> id => name
     */
    public function activeOptions(): array;

    /** How many active buildings the organization has. */
    public function countActive(): int;
}
