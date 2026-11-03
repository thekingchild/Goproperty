<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use App\Enums\SubscriptionStatus;
use App\Enums\TransactionTier;
use App\Exports\ExportCell;
use App\Support\Money;

/**
 * The System analytics page's CSV downloads (P08-02; PLATFORM_CONSOLE.md
 * § 3 "Every chart has a date range filter and CSV export"): one per
 * section, for the page's range, from the same PlatformAnalytics the page
 * shows. Daily sections have a row per day (empty cells where the day has
 * no figure); money in naira with two decimals; cells through ExportCell
 * (formula injection neutralised), as the workspace's list exports.
 */
final class AnalyticsCsv
{
    public const array SECTIONS = [
        'revenue' => 'Revenue',
        'customers' => 'Customers',
        'states' => 'Customers by state',
        'usage' => 'Usage',
        'tiers' => 'Transaction Tiers',
        'collections' => 'Collections recorded',
        'leaderboard' => 'Leaderboard',
    ];

    /** @return array{headers: list<string>, rows: list<list<string|int|null>>} */
    public static function section(string $section, PlatformAnalytics $analytics): array
    {
        return match ($section) {
            'revenue' => self::daily($analytics, [
                'MRR' => [PlatformMetric::MrrKobo],
                'Paying customers' => [PlatformMetric::PayingCustomers],
                'Invoiced (VAT included)' => [PlatformMetric::InvoicedKobo],
                'Invoiced before VAT' => [PlatformMetric::InvoicedNetKobo],
                'Received' => [PlatformMetric::ReceivedKobo],
                'Refunded' => [PlatformMetric::RefundedKobo],
                'Invoices paid' => [PlatformMetric::InvoicesPaid],
                'Days to pay (total)' => [PlatformMetric::DaysToPay],
                'Outstanding' => [PlatformMetric::OutstandingKobo],
                'Outstanding invoices' => [PlatformMetric::OutstandingInvoices],
                'Overdue' => [PlatformMetric::OverdueKobo],
            ]),
            'customers' => self::daily($analytics, [
                'Signups' => [PlatformMetric::Signups],
                'Signups: agencies' => [PlatformMetric::Signups, 'agency'],
                'Signups: landlords' => [PlatformMetric::Signups, 'property_owner'],
                'Signups: both' => [PlatformMetric::Signups, 'hybrid'],
                'Trials ended' => [PlatformMetric::TrialsEnded],
                'First payments' => [PlatformMetric::TrialConversions],
                'Paying customers who cancelled' => [PlatformMetric::Cancellations],
                'Trials cancelled' => [PlatformMetric::TrialCancellations],
                ...array_combine(
                    array_map(static fn (SubscriptionStatus $status): string => 'Customers: '.$status->label(), SubscriptionStatus::cases()),
                    array_map(static fn (SubscriptionStatus $status): array => [PlatformMetric::CustomersByStatus, $status->value], SubscriptionStatus::cases()),
                ),
                'Customers: no plan chosen yet' => [PlatformMetric::CustomersByStatus, 'none'],
                'Platform-covered organizations' => [PlatformMetric::CoveredOrganizations],
            ]),
            'states' => [
                'headers' => ['State', 'Customers', 'As of'],
                'rows' => array_map(
                    static fn (string $state, int $count): array => [ExportCell::value($state === 'unknown' ? 'Not given' : $state), $count, $analytics->stocksDay()?->toDateString()],
                    array_map('strval', array_keys($analytics->latestByDimension(PlatformMetric::OrganizationsByState))),
                    array_values($analytics->latestByDimension(PlatformMetric::OrganizationsByState)),
                ),
            ],
            'usage' => self::daily($analytics, [
                'Units' => [PlatformMetric::UnitsTotal],
                'Units added' => [PlatformMetric::UnitsAdded],
                'People signed in that day' => [PlatformMetric::ActiveUsersDay],
                'People signed in, 7 days to that day' => [PlatformMetric::ActiveUsersWeek],
                'Tenants with portal access' => [PlatformMetric::PortalTenants],
                'Tickets opened' => [PlatformMetric::TicketsOpened],
                'Tickets resolved' => [PlatformMetric::TicketsResolved],
            ]),
            'tiers' => self::daily($analytics, [
                ...array_combine(
                    array_map(static fn (TransactionTier $tier): string => 'Organizations: '.$tier->label(), TransactionTier::cases()),
                    array_map(static fn (TransactionTier $tier): array => [PlatformMetric::OrganizationsByTier, (string) $tier->value], TransactionTier::cases()),
                ),
                'Applications waiting' => [PlatformMetric::TierApplicationsPending],
                'Applications approved' => [PlatformMetric::TierApplicationsApproved],
                'Applications rejected' => [PlatformMetric::TierApplicationsRejected],
                'Open compliance flags' => [PlatformMetric::ComplianceFlagsOpen],
            ]),
            'collections' => self::daily($analytics, [
                'Recorded' => [PlatformMetric::CollectionsKobo],
                'Payments' => [PlatformMetric::Collections],
                ...array_combine(
                    array_map(static fn (CollectionChannel $channel): string => $channel->label(), CollectionChannel::cases()),
                    array_map(static fn (CollectionChannel $channel): array => [PlatformMetric::CollectionsKobo, $channel->value], CollectionChannel::cases()),
                ),
            ]),
            'leaderboard' => self::leaderboard($analytics),
            default => throw new \InvalidArgumentException("No analytics section \"{$section}\"."),
        };
    }

    /**
     * @param  array<string, array{0: PlatformMetric, 1?: string}>  $columns
     * @return array{headers: list<string>, rows: list<list<string|int|null>>}
     */
    private static function daily(PlatformAnalytics $analytics, array $columns): array
    {
        $series = array_map(static fn (array $column): array => $analytics->daily($column[0], $column[1] ?? ''), $columns);
        $rows = [];

        foreach ($analytics->range->days() as $day) {
            $row = [$day];

            foreach ($columns as $header => $column) {
                $value = $series[$header][$day];
                $row[] = $value === null ? null : ($column[0]->isMoney() ? ExportCell::value(Money::kobo($value)) : $value);
            }

            $rows[] = $row;
        }

        return ['headers' => ['Date', ...array_keys($columns)], 'rows' => $rows];
    }

    /** @return array{headers: list<string>, rows: list<list<string|int|null>>} */
    private static function leaderboard(PlatformAnalytics $analytics): array
    {
        $boards = ['units' => 'By units', 'growth' => 'By units added', 'reliability' => 'By payment reliability'];
        $rows = [];

        foreach ($analytics->leaderboard() as $board => $entries) {
            foreach ($entries as $rank => $entry) {
                $rows[] = [
                    $boards[$board],
                    $rank + 1,
                    ExportCell::value($entry->name),
                    $entry->units,
                    $entry->growth,
                    $entry->invoicesDue,
                    $entry->paidOnTime,
                    $entry->reliability() === null ? null : PlatformAnalytics::percent($entry->reliability()),
                ];
            }
        }

        return ['headers' => ['Board', 'Rank', 'Customer', 'Units', 'Units added', 'Invoices due (year)', 'Paid on time', 'Reliability'], 'rows' => $rows];
    }
}
