<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Works out one Lagos day's platform figures and writes them to
 * `daily_platform_metrics` (P08-02; PLATFORM_CONSOLE.md § 3; definitions
 * in docs/plans/P08-subtasks.md "Defaults taken in P08-02"). The only
 * writer of that table.
 *
 * A console read model (App\Platform may read every organization;
 * ARCHITECTURE.md § 4): it reads the tables directly, never the
 * organization-scoped models, and changes nothing but its own table. Every
 * query is a plain count, sum or grouping (the same on MySQL 8 and MariaDB
 * 10.6+); Lagos dates of timestamps are worked out in PHP.
 *
 * Idempotent: a day's flows (and, when taken, its stocks) are replaced as
 * a whole in one transaction, so running it again for the same day leaves
 * the same rows, never a second set; the unique (date, metric, dimension)
 * backs that up.
 */
final readonly class MetricsSnapshot
{
    public const string TIMEZONE = 'Africa/Lagos';

    /** Subscription states that pay GoProperty (MRR): invoiced, whether or not the latest invoice is paid yet. */
    public const array PAYING = [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Grace];

    /** Sign-in events that count a person as active (App\Audit\AuthenticationLog::EVENTS). */
    private const array SIGN_INS = ['login_success', 'passkey_login'];

    /** How far back the leaderboard's payment reliability looks. */
    public const int RELIABILITY_DAYS = 365;

    public function __construct(private PlanCatalogue $plans) {}

    /**
     * Take the day's flows and, with $withStocks, its stocks as they stand
     * now. Returns the rows written.
     */
    public function take(CarbonImmutable $day, bool $withStocks): int
    {
        $day = CarbonImmutable::parse($day->format('Y-m-d'), self::TIMEZONE)->startOfDay();

        if ($day->greaterThanOrEqualTo(CarbonImmutable::now(self::TIMEZONE)->startOfDay())) {
            throw new InvalidArgumentException("{$day->toDateString()} has not ended yet in Lagos.");
        }

        $now = CarbonImmutable::now();
        $rows = new MetricRows;
        $this->flows($day, $rows);
        $rows->add(PlatformMetric::Snapshot, $now->getTimestamp(), 'flows');
        $metrics = [...PlatformMetric::flows()];

        if ($withStocks) {
            $this->stocks($now, $rows);
            $rows->add(PlatformMetric::Snapshot, $now->getTimestamp(), 'stocks');
            $metrics = [...$metrics, ...PlatformMetric::stocks()];
        }

        $date = $day->toDateString();
        $records = array_map(static fn (array $row): array => ['date' => $date, ...$row, 'created_at' => $now, 'updated_at' => $now], $rows->all());

        DB::transaction(function () use ($date, $metrics, $withStocks, $records): void {
            DB::table('daily_platform_metrics')
                ->where('date', $date)
                ->where(fn ($query) => $query
                    ->whereIn('metric', array_map(static fn (PlatformMetric $metric): string => $metric->value, $metrics))
                    ->orWhere(fn ($marker) => $marker->where('metric', PlatformMetric::Snapshot->value)->whereIn('dimension', $withStocks ? ['flows', 'stocks'] : ['flows'])))
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('daily_platform_metrics')->insert($chunk);
            }
        }, 3);

        return count($records);
    }

    /** What happened during the Lagos day, from the records' own times. */
    private function flows(CarbonImmutable $day, MetricRows $rows): void
    {
        [$from, $to] = self::window($day);
        $covered = $this->coveredOrganizations();

        // Revenue: GoProperty's invoices issued (not void), money received and refunded.
        $invoiced = DB::table('platform_invoices')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('status', '!=', 'void')
            ->get(['organization_id', 'kind', 'total_kobo', 'subtotal_kobo'])
            ->reject(static fn (object $invoice): bool => isset($covered[(int) $invoice->organization_id]));

        $rows->total(PlatformMetric::InvoicedKobo, $invoiced->sum(static fn (object $i): int => (int) $i->total_kobo));
        $rows->total(PlatformMetric::InvoicedNetKobo, $invoiced->sum(static fn (object $i): int => (int) $i->subtotal_kobo));

        foreach ($invoiced->groupBy('kind') as $kind => $ofKind) {
            $rows->add(PlatformMetric::InvoicedKobo, $ofKind->sum(static fn (object $i): int => (int) $i->total_kobo), (string) $kind);
            $rows->add(PlatformMetric::InvoicedNetKobo, $ofKind->sum(static fn (object $i): int => (int) $i->subtotal_kobo), (string) $kind);
        }

        $rows->total(PlatformMetric::ReceivedKobo, (int) DB::table('platform_payments')
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $from)->where('confirmed_at', '<', $to)
            ->sum('amount_kobo'));

        $rows->total(PlatformMetric::RefundedKobo, (int) DB::table('platform_refunds')
            ->where('status', 'processed')
            ->where('processed_at', '>=', $from)->where('processed_at', '<', $to)
            ->sum('amount_kobo'));

        $paid = DB::table('platform_invoices')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)
            ->get(['organization_id', 'created_at', 'paid_at'])
            ->reject(static fn (object $invoice): bool => isset($covered[(int) $invoice->organization_id]));

        $rows->total(PlatformMetric::InvoicesPaid, $paid->count());
        $rows->total(PlatformMetric::DaysToPay, $paid->sum(static fn (object $invoice): int => (int) self::lagosDay((string) $invoice->created_at)->diffInDays(self::lagosDay((string) $invoice->paid_at))));

        // Customers: signups by type, trials ended, first payments, cancellations.
        $signups = DB::table('organizations')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->groupBy('type')
            ->select('type')->selectRaw('COUNT(*) AS organizations')
            ->get();

        $rows->total(PlatformMetric::Signups, $signups->sum(static fn (object $row): int => (int) $row->organizations));

        foreach ($signups as $row) {
            $rows->add(PlatformMetric::Signups, (int) $row->organizations, (string) $row->type);
        }

        $rows->total(PlatformMetric::TrialsEnded, DB::table('subscriptions')
            ->where('trial_ends_at', '>=', $from)->where('trial_ends_at', '<', $to)
            ->pluck('organization_id')
            ->reject(static fn (mixed $id): bool => isset($covered[(int) $id]))
            ->count());

        // A trial becomes paid when the organization's first subscription invoice is paid.
        $rows->total(PlatformMetric::TrialConversions, DB::table('platform_payments')
            ->join('platform_invoices', 'platform_invoices.id', '=', 'platform_payments.platform_invoice_id')
            ->where('platform_payments.status', 'confirmed')
            ->where('platform_invoices.kind', 'subscription')
            ->groupBy('platform_invoices.organization_id')
            ->havingRaw('MIN(platform_payments.confirmed_at) >= ? AND MIN(platform_payments.confirmed_at) < ?', [$from, $to])
            ->pluck('platform_invoices.organization_id')
            ->reject(static fn (mixed $id): bool => isset($covered[(int) $id]))
            ->count());

        $cancelled = DB::table('activity_log')
            ->where('log_name', 'subscriptions')
            ->where('event', 'subscription.cancelled')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->pluck('properties')
            ->map(static fn (mixed $json): array => is_string($json) ? (array) json_decode($json, true) : [])
            ->reject(static fn (array $properties): bool => isset($covered[(int) ($properties['organization_id'] ?? 0)]));
        $fromTrial = $cancelled->filter(static fn (array $properties): bool => ($properties['old']['status'] ?? null) === SubscriptionStatus::Trialing->value)->count();

        $rows->total(PlatformMetric::Cancellations, $cancelled->count() - $fromTrial);
        $rows->total(PlatformMetric::TrialCancellations, $fromTrial);

        // Usage.
        $rows->total(PlatformMetric::UnitsAdded, DB::table('units')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->count());

        $rows->total(PlatformMetric::ActiveUsersDay, $this->signedIn($from, $to));
        $rows->total(PlatformMetric::ActiveUsersWeek, $this->signedIn(self::window($day->subDays(6))[0], $to));

        $rows->total(PlatformMetric::TicketsOpened, DB::table('tickets')
            ->whereNull('deleted_at')
            ->where('submitted_at', '>=', $from)->where('submitted_at', '<', $to)
            ->count());

        $rows->total(PlatformMetric::TicketsResolved, DB::table('tickets')
            ->whereNull('deleted_at')
            ->where('resolved_at', '>=', $from)->where('resolved_at', '<', $to)
            ->count());

        // Transaction Tiers: decisions made that day.
        $decided = DB::table('tier_applications')
            ->where('decided_at', '>=', $from)->where('decided_at', '<', $to)
            ->groupBy('status')
            ->select('status')->selectRaw('COUNT(*) AS applications')
            ->pluck('applications', 'status');

        $rows->total(PlatformMetric::TierApplicationsApproved, (int) ($decided['approved'] ?? 0));
        $rows->total(PlatformMetric::TierApplicationsRejected, (int) ($decided['rejected'] ?? 0));

        // Collections agencies recorded (confirmed that day), by channel.
        $collections = DB::table('payments')
            ->whereNull('deleted_at')
            ->whereIn('status', ['confirmed', 'refunded'])
            ->where('source', '!=', 'import')
            ->where('confirmed_at', '>=', $from)->where('confirmed_at', '<', $to)
            ->groupBy('source', 'method')
            ->select(['source', 'method'])
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('SUM(amount_kobo) AS amount')
            ->get();

        $byChannel = [];

        foreach ($collections as $row) {
            $channel = CollectionChannel::of((string) $row->source, (string) $row->method);

            if ($channel !== null) {
                $byChannel[$channel->value][0] = ($byChannel[$channel->value][0] ?? 0) + (int) $row->amount;
                $byChannel[$channel->value][1] = ($byChannel[$channel->value][1] ?? 0) + (int) $row->payments;
            }
        }

        $rows->total(PlatformMetric::CollectionsKobo, array_sum(array_column($byChannel, 0)));
        $rows->total(PlatformMetric::Collections, array_sum(array_column($byChannel, 1)));

        foreach ($byChannel as $channel => [$amount, $count]) {
            $rows->add(PlatformMetric::CollectionsKobo, $amount, $channel);
            $rows->add(PlatformMetric::Collections, $count, $channel);
        }
    }

    /** Counts and amounts as they stand now. */
    private function stocks(CarbonImmutable $now, MetricRows $rows): void
    {
        $today = $now->setTimezone(self::TIMEZONE)->startOfDay();
        $covered = $this->coveredOrganizations();
        $organizations = DB::table('organizations')->whereNull('deleted_at')->get(['id', 'status', 'state', 'transaction_tier']);
        $customers = $organizations->reject(static fn (object $organization): bool => isset($covered[(int) $organization->id]));
        $open = $organizations->filter(static fn (object $organization): bool => $organization->status !== 'cancelled');
        $subscriptions = DB::table('subscriptions')->get(['organization_id', 'plan_id', 'billing_cycle', 'status'])->keyBy('organization_id');
        $units = $this->billableUnits();

        // MRR: each paying customer's monthly price at its plan's terms in force today, before VAT.
        $mrr = [];
        $paying = [];

        foreach ($customers as $organization) {
            $subscription = $subscriptions->get($organization->id);

            if ($subscription === null || ! in_array(SubscriptionStatus::tryFrom((string) $subscription->status), self::PAYING, true)) {
                continue;
            }

            $plan = $this->plans->findById((int) $subscription->plan_id, $today);

            if (! $plan instanceof Plan) {
                continue;
            }

            $monthly = $this->monthly($plan, $units[(int) $organization->id] ?? 0, (string) $subscription->billing_cycle);
            $mrr[$plan->key] = ($mrr[$plan->key] ?? 0) + $monthly;
            $paying[$plan->key] = ($paying[$plan->key] ?? 0) + 1;
        }

        $rows->total(PlatformMetric::MrrKobo, array_sum($mrr));
        $rows->total(PlatformMetric::PayingCustomers, array_sum($paying));

        foreach ($mrr as $plan => $amount) {
            $rows->add(PlatformMetric::MrrKobo, $amount, $plan);
            $rows->add(PlatformMetric::PayingCustomers, $paying[$plan], $plan);
        }

        $outstanding = DB::table('platform_invoices')->where('status', 'open')->get(['total_kobo', 'due_date']);
        $rows->total(PlatformMetric::OutstandingKobo, $outstanding->sum(static fn (object $i): int => (int) $i->total_kobo));
        $rows->total(PlatformMetric::OutstandingInvoices, $outstanding->count());
        $rows->total(PlatformMetric::OverdueKobo, $outstanding
            ->filter(static fn (object $i): bool => $i->due_date !== null && substr((string) $i->due_date, 0, 10) < $today->toDateString())
            ->sum(static fn (object $i): int => (int) $i->total_kobo));

        // Customers (platform-covered organizations apart) by subscription state and by Nigerian state.
        foreach ($customers->countBy(static fn (object $organization): string => (string) ($subscriptions->get($organization->id)->status ?? 'none')) as $status => $count) {
            $rows->add(PlatformMetric::CustomersByStatus, $count, (string) $status);
        }

        $rows->total(PlatformMetric::CustomersByStatus, $customers->count());
        $rows->total(PlatformMetric::CoveredOrganizations, $organizations->count() - $customers->count());

        $openCustomers = $customers->filter(static fn (object $organization): bool => $organization->status !== 'cancelled');

        foreach ($openCustomers->countBy(static fn (object $organization): string => mb_substr(trim((string) $organization->state), 0, 64) ?: 'unknown') as $state => $count) {
            $rows->add(PlatformMetric::OrganizationsByState, $count, (string) $state);
        }

        $rows->total(PlatformMetric::OrganizationsByState, $openCustomers->count());

        // Usage: billable units of organizations still open, tenants who can sign in to the portal.
        $rows->total(PlatformMetric::UnitsTotal, $open->sum(static fn (object $organization): int => $units[(int) $organization->id] ?? 0));
        $rows->total(PlatformMetric::PortalTenants, (int) DB::table('tenant_profiles')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->selectRaw('COUNT(DISTINCT user_id) AS people')
            ->value('people'));

        // Transaction Tiers.
        foreach ($open->countBy(static fn (object $organization): string => (string) ($organization->transaction_tier ?? 1)) as $tier => $count) {
            $rows->add(PlatformMetric::OrganizationsByTier, $count, (string) $tier);
        }

        $rows->total(PlatformMetric::OrganizationsByTier, $open->count());
        $rows->total(PlatformMetric::TierApplicationsPending, DB::table('tier_applications')->whereIn('status', ['submitted', 'under_review', 'more_info_needed'])->count());
        $rows->total(PlatformMetric::ComplianceFlagsOpen, DB::table('compliance_flags')->whereIn('status', ['open', 'acknowledged'])->count());

        // Per customer, for the leaderboard: units, and invoices due in the last year paid by their due date.
        foreach ($openCustomers as $organization) {
            $count = $units[(int) $organization->id] ?? 0;

            if ($count > 0) {
                $rows->add(PlatformMetric::OrgUnits, $count, (string) $organization->id);
            }
        }

        $since = $today->subDays(self::RELIABILITY_DAYS)->toDateString();
        $due = DB::table('platform_invoices')
            ->where('kind', 'subscription')
            ->where('status', '!=', 'void')
            ->whereDate('due_date', '>=', $since)
            ->whereDate('due_date', '<', $today->toDateString())
            ->get(['organization_id', 'due_date', 'paid_at'])
            ->reject(static fn (object $invoice): bool => isset($covered[(int) $invoice->organization_id]));

        foreach ($due->groupBy('organization_id') as $organizationId => $invoices) {
            $onTime = $invoices->filter(static fn (object $invoice): bool => $invoice->paid_at !== null
                && self::lagosDay((string) $invoice->paid_at)->toDateString() <= substr((string) $invoice->due_date, 0, 10))->count();
            $rows->add(PlatformMetric::OrgInvoicesDue, $invoices->count(), (string) $organizationId);
            $rows->add(PlatformMetric::OrgInvoicesOnTime, $onTime, (string) $organizationId);
        }
    }

    /**
     * The monthly price before VAT: the plan's price for the units; a yearly
     * subscription pays 10 months for 12 (BILLING_PAYMENTS.md § 1.4), so its
     * month is a twelfth of that, to the nearest kobo.
     */
    private function monthly(Plan $plan, int $units, string $cycle): int
    {
        $month = $plan->priceFor($units);

        return $cycle === BillingCycle::Annual->value
            ? $month->times($this->plans->monthsPaidPerYear())->dividedBy(12)->kobo
            : $month->kobo;
    }

    /**
     * Units by organization, counted as metering counts them
     * (BILLING_PAYMENTS.md § 1.4; Properties' UnitDirectory::countBillable()):
     * not deleted, in a building that is active and not deleted.
     *
     * @return array<int, int>
     */
    private function billableUnits(): array
    {
        return DB::table('units')
            ->join('properties', 'properties.id', '=', 'units.property_id')
            ->whereNull('units.deleted_at')
            ->whereNull('properties.deleted_at')
            ->where('properties.status', 'active')
            ->groupBy('units.organization_id')
            ->select('units.organization_id')
            ->selectRaw('COUNT(*) AS units')
            ->pluck('units', 'organization_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    /**
     * Platform-covered organizations (BILLING_PAYMENTS.md § 2): covered
     * themselves, or billed to a covered payer. They pay GoProperty nothing,
     * so revenue, customers and the leaderboard leave them out.
     *
     * @return array<int, true>
     */
    private function coveredOrganizations(): array
    {
        $covered = DB::table('organizations')->where('platform_covered', true)->pluck('id')->map(static fn (mixed $id): int => (int) $id);
        $billedToCovered = DB::table('subscriptions')->whereIn('payer_organization_id', $covered->all())->pluck('organization_id')->map(static fn (mixed $id): int => (int) $id);

        return array_fill_keys($covered->merge($billedToCovered)->unique()->values()->all(), true);
    }

    /** Customer users (not staff) who signed in between the two times. */
    private function signedIn(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('authentication_logs')
            ->where('authenticatable_type', 'user')
            ->whereNotNull('authenticatable_id')
            ->whereIn('event', self::SIGN_INS)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('COUNT(DISTINCT authenticatable_id) AS people')
            ->value('people');
    }

    /**
     * The Lagos day as a UTC half-open interval [from, to), how timestamps
     * are stored (config/app.php: UTC).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function window(CarbonImmutable $day): array
    {
        $start = CarbonImmutable::parse($day->format('Y-m-d'), self::TIMEZONE)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** The Lagos calendar day of a stored (UTC) timestamp. */
    private static function lagosDay(string $timestamp): CarbonImmutable
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay();
    }
}
