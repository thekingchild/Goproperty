<?php

declare(strict_types=1);

namespace Modules\Leasing\Contracts;

/**
 * Which tenants hold the live leases (draft, reserved or active) of some
 * buildings: the tenants a management handover moves with them
 * (BILLING_PAYMENTS.md § 7.4), for the Tenants module's part of the move
 * and the handover's tenant credit list (P03 decision 11). Leases removed
 * by an import rollback do not count. Answers for the current
 * organization only; no permission check. Bound in LeasingServiceProvider.
 */
interface LiveLeases
{
    /**
     * @param  list<int>  $propertyIds
     * @return list<int> tenant profile ids, ascending
     */
    public function tenantIdsIn(array $propertyIds): array;
}
