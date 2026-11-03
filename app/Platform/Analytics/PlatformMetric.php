<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

/**
 * Every figure the nightly snapshot keeps in `daily_platform_metrics`
 * (P08-02; PLATFORM_CONSOLE.md § 3, docs/plans/P08-subtasks.md "Defaults
 * taken in P08-02").
 *
 * - A **stock** is a count or amount as it stands when the snapshot runs
 *   (just after the Lagos day ends): MRR, customers by status, units. It
 *   cannot be worked out again for a past day, so a day missed for longer
 *   than the catch-up keeps no stocks (MetricsSnapshot).
 * - A **flow** is what happened during the Lagos day, from the records'
 *   own times (signups, money received, tickets opened); it can be worked
 *   out again for any day. The rolling active-user counts are flows too:
 *   they read the sign-in log for the day and the 7 days ending it.
 *
 * Amounts end in `_kobo` (integer kobo, AGENT.md § 3 rule 2).
 */
enum PlatformMetric: string
{
    // Stocks: revenue.
    case MrrKobo = 'mrr_kobo';
    case PayingCustomers = 'paying_customers';
    case OutstandingKobo = 'outstanding_kobo';
    case OutstandingInvoices = 'outstanding_invoices';
    case OverdueKobo = 'overdue_kobo';

    // Stocks: customers.
    case CustomersByStatus = 'customers_by_status';
    case CoveredOrganizations = 'covered_organizations';
    case OrganizationsByState = 'organizations_by_state';

    // Stocks: usage.
    case UnitsTotal = 'units_total';
    case PortalTenants = 'portal_tenants';

    // Stocks: Transaction Tiers.
    case OrganizationsByTier = 'organizations_by_tier';
    case TierApplicationsPending = 'tier_applications_pending';
    case ComplianceFlagsOpen = 'compliance_flags_open';

    // Stocks per organization (dimension = organization id), for the leaderboard.
    case OrgUnits = 'org_units';
    case OrgInvoicesDue = 'org_invoices_due';
    case OrgInvoicesOnTime = 'org_invoices_on_time';

    // Flows: revenue.
    case InvoicedKobo = 'invoiced_kobo';
    case InvoicedNetKobo = 'invoiced_net_kobo';
    case ReceivedKobo = 'received_kobo';
    case RefundedKobo = 'refunded_kobo';
    case InvoicesPaid = 'invoices_paid';
    case DaysToPay = 'days_to_pay';

    // Flows: customers.
    case Signups = 'signups';
    case TrialsEnded = 'trials_ended';
    case TrialConversions = 'trial_conversions';
    case Cancellations = 'cancellations';
    case TrialCancellations = 'trial_cancellations';

    // Flows: usage.
    case UnitsAdded = 'units_added';
    case ActiveUsersDay = 'active_users_1d';
    case ActiveUsersWeek = 'active_users_7d';
    case TicketsOpened = 'tickets_opened';
    case TicketsResolved = 'tickets_resolved';

    // Flows: Transaction Tiers.
    case TierApplicationsApproved = 'tier_applications_approved';
    case TierApplicationsRejected = 'tier_applications_rejected';

    // Flows: collections recorded by agencies (dimension = channel).
    case CollectionsKobo = 'collections_kobo';
    case Collections = 'collections';

    // When the day's flows and stocks were taken (value: Unix time; dimension `flows` or `stocks`).
    case Snapshot = 'snapshot';

    public function isStock(): bool
    {
        return in_array($this, self::stocks(), true);
    }

    /** @return list<self> */
    public static function stocks(): array
    {
        return [
            self::MrrKobo, self::PayingCustomers, self::OutstandingKobo, self::OutstandingInvoices, self::OverdueKobo,
            self::CustomersByStatus, self::CoveredOrganizations, self::OrganizationsByState,
            self::UnitsTotal, self::PortalTenants,
            self::OrganizationsByTier, self::TierApplicationsPending, self::ComplianceFlagsOpen,
            self::OrgUnits, self::OrgInvoicesDue, self::OrgInvoicesOnTime,
        ];
    }

    /** @return list<self> */
    public static function flows(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $metric): bool => $metric !== self::Snapshot && ! $metric->isStock()));
    }

    /** Per-organization stocks: read for two days only, never for the whole range. */
    public function isPerOrganization(): bool
    {
        return in_array($this, [self::OrgUnits, self::OrgInvoicesDue, self::OrgInvoicesOnTime], true);
    }

    public function isMoney(): bool
    {
        return str_ends_with($this->value, '_kobo');
    }
}
