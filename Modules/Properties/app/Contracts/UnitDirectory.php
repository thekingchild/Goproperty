<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;

/**
 * How other modules read units and set the status a lease gives them
 * (ARCHITECTURE.md § 6.2, § 8 rule 3): never the Properties module's models
 * or tables. Answers for the current organization only (OrganizationContext;
 * without one it throws App\Tenancy\MissingOrganizationContext), so another
 * organization's unit is simply not found; archived (soft-deleted) units are
 * not found either.
 *
 * No permission check: the caller checks its own policy for the user it
 * acts for (an assigned agent's buildings: Contracts\BuildingAccess).
 * countBillable() is the organization's bill and is never narrowed. Bound
 * in PropertiesServiceProvider.
 */
interface UnitDirectory
{
    /** Status values (`units.status`, DATABASE_SCHEMA.md § 3). */
    public const string VACANT = 'vacant';

    public const string RESERVED = 'reserved';

    public const string OCCUPIED = 'occupied';

    public const string UNDER_MAINTENANCE = 'under_maintenance';

    public const string UNAVAILABLE = 'unavailable';

    /** The unit, or null when the current organization has no such (live) unit. */
    public function find(int $unitId): ?UnitSummary;

    /**
     * The units of these ids that the current organization has; unknown ids
     * are left out.
     *
     * @param  list<int>  $unitIds
     * @return array<int, UnitSummary> by id
     */
    public function findMany(array $unitIds): array;

    /**
     * The building's live units (not archived), by label with "Flat 2"
     * before "Flat 10": for a picker such as the lease form's. Empty for an
     * unknown building.
     *
     * @return array<int, UnitSummary> by id
     */
    public function inProperty(int $propertyId): array;

    /** The building's units by status (zeros for an unknown building). */
    public function countsForProperty(int $propertyId): UnitCounts;

    /**
     * The organization's units by status, in buildings that are not
     * archived; with $for, only those of the buildings that member sees
     * (an assigned agent's, P02-03), for a count shown to them.
     */
    public function counts(?User $for = null): UnitCounts;

    /**
     * Units the GoProperty bill counts (BILLING_PAYMENTS.md § 1.4): every
     * unit not archived (soft-deleted) whose building is not archived,
     * whatever its status.
     */
    public function countBillable(): int;

    /**
     * Set the status a lease gives the unit (`occupied` when a lease starts,
     * `reserved` for a draft, `vacant` when it ends), audited with the
     * reason. Leasing calls it inside its own transaction, after lock().
     *
     * @throws InvalidArgumentException for an unknown status value
     * @throws ModelNotFoundException when there is no such unit here
     */
    public function setStatus(int $unitId, string $status, string $reason): UnitSummary;

    /**
     * Lock the unit's row (SELECT … FOR UPDATE) until the caller's
     * transaction ends, and return it as it is now; null when there is no
     * such unit here. Two lease activations on one unit wait for each other
     * here, so only one sees it vacant (P03-03a).
     *
     * @throws LogicException outside a database transaction (the lock would end at once)
     */
    public function lock(int $unitId): ?UnitSummary;
}
