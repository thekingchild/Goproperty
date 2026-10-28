<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

/**
 * How the owner portal (P03-15, the Properties module's /owner pages)
 * reads the bills of an owner's buildings: never the Billing module's
 * models or tables (ARCHITECTURE.md § 8 rule 3). Read-only: no payment
 * details, no invoice (the printed invoice carries the tenant's phone and
 * email, which owners never see).
 *
 * Every method takes the building ids the portal proved belong to the
 * signed-in owner in the current organization (Modules\Properties\
 * OwnerPortal, the "linked to my user" path of ARCHITECTURE.md § 4) and
 * answers only for bills of those buildings (`bills.property_id`): any
 * other bill id — another owner's, or another organization's, which the
 * organization scope hides anyway — is simply not found (null). Bills
 * removed by an import rollback are not found. No permission check: the
 * caller checks its policy. Bound in BillingServiceProvider.
 */
interface OwnerBills
{
    /**
     * The bills of these buildings (of one lease among them when given),
     * void ones included, latest due date first, at most $limit.
     *
     * @param  list<int>  $propertyIds
     * @return list<OwnerBill> without lines
     */
    public function forBuildings(array $propertyIds, ?int $leaseId = null, int $limit = 50): array;

    /**
     * How many bills these buildings have (of one lease among them when given).
     *
     * @param  list<int>  $propertyIds
     */
    public function countFor(array $propertyIds, ?int $leaseId = null): int;

    /**
     * One bill of these buildings with its lines, or null.
     *
     * @param  list<int>  $propertyIds
     */
    public function find(int $billId, array $propertyIds): ?OwnerBill;
}
