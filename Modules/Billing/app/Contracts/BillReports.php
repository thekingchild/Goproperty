<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use Carbon\CarbonImmutable;

/**
 * How the customer dashboards (P08-01, the Reports module) read bills:
 * never the Billing module's models or tables (ARCHITECTURE.md § 8 rule 3).
 * Read-only, plain values (ReportBill), the tenant and unit named as the
 * bill was issued (`bills.details`); never the payment reference or the
 * tenant's phone and email.
 *
 * `$propertyIds` narrows to bills of those buildings (`bills.property_id`);
 * null means every bill of the organization, bills of no building
 * included. Bills removed by an import rollback are never found. Answers
 * for the current organization only (OrganizationContext); no permission
 * check: the caller checks its policy. Bound in BillingServiceProvider.
 */
interface BillReports
{
    /**
     * Bills issued in [$from, $to) (`issued_at`), void ones included (the
     * caller leaves them out), by issue time.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<ReportBill>
     */
    public function issuedBetween(CarbonImmutable $from, CarbonImmutable $to, ?array $propertyIds = null): array;

    /**
     * Bills that still ask for money now (unpaid, partly paid or overdue),
     * oldest due date first.
     *
     * @param  list<int>|null  $propertyIds
     * @return list<ReportBill>
     */
    public function open(?array $propertyIds = null): array;

    /**
     * The bills of these ids that the current organization has (any
     * building, void ones included); unknown ids are left out.
     *
     * @param  list<int>  $billIds
     * @return array<int, ReportBill> by id
     */
    public function findMany(array $billIds): array;
}
