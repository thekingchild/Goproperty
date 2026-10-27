<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

use App\Models\User;

/**
 * Which buildings a member works on (ARCHITECTURE.md § 5, agent narrowing;
 * DATABASE_SCHEMA.md § 3 `agent_building`), for other modules that list or
 * check records by building (leases, bills, tickets): never the
 * `agent_building` table itself.
 *
 * An agent (holding `agent` here, and neither `org-owner` nor `org-admin`)
 * with at least one assignment in the current organization is limited to
 * the assigned buildings. Everyone else, including an agent with no
 * assignment, is not limited: narrowing is optional, per agent. Answers for
 * the current organization only (without one it throws
 * App\Tenancy\MissingOrganizationContext). It says nothing about
 * permissions or whether the building exists: the caller's policy checks
 * those. Bound in PropertiesServiceProvider.
 */
interface BuildingAccess
{
    /**
     * The ids of the buildings the member is limited to, or null when they
     * see every building of the organization.
     *
     * @return list<int>|null
     */
    public function limitedTo(User $user): ?array;

    /** Whether the member's narrowing lets them see the building. */
    public function allows(User $user, int $propertyId): bool;
}
