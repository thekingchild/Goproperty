<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

use Carbon\CarbonImmutable;

/**
 * How the owner portal (P03-15, the Properties module's /owner pages)
 * reads the leases of an owner's buildings: never the Leasing module's
 * models or tables (ARCHITECTURE.md § 8 rule 3). Read-only.
 *
 * Every method takes the building ids the portal proved belong to the
 * signed-in owner in the current organization (Modules\Properties\
 * OwnerPortal, the "linked to my user" path of ARCHITECTURE.md § 4) and
 * answers only for leases in those buildings: any other lease id — another
 * owner's, or another organization's, which the organization scope hides
 * anyway — is simply not found (null). Drafts are an agency's work in
 * progress and are never shown; leases removed by an import rollback are
 * not found. No permission check: the caller checks its policy. Bound in
 * LeasingServiceProvider.
 */
interface OwnerLeases
{
    /**
     * The active leases in these buildings, by unit.
     *
     * @param  list<int>  $propertyIds
     * @return list<LeaseSummary>
     */
    public function activeIn(array $propertyIds): array;

    /**
     * One lease (any status but draft) in these buildings, or null.
     *
     * @param  list<int>  $propertyIds
     */
    public function find(int $leaseId, array $propertyIds): ?LeaseSummary;

    /**
     * The active leases in these buildings whose end date falls between
     * $from and $to (both days included), soonest first, each with its
     * draft renewal if one was made (the owner statements' upcoming
     * renewals, P08-05).
     *
     * @param  list<int>  $propertyIds
     * @return list<EndingLease>
     */
    public function endingBetween(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
