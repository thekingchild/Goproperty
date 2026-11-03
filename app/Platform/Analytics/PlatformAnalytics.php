<?php

declare(strict_types=1);

namespace App\Platform\Analytics;

use App\Platform\Customers\Customer;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The figures of the console's System analytics page for one date range
 * (P08-02; PLATFORM_CONSOLE.md § 3), read only from `daily_platform_metrics`
 * so the page never queries live customer tables. One query loads the
 * range's daily rows; two more the stocks of the range's first and last
 * days (per-organization ones included, for the leaderboard).
 *
 * Flows add up over the days of the range that were taken; stocks are read
 * from the latest day on or before the range's end that has them, and
 * compared with the day before the range starts (or its first day with
 * stocks). Rates are in tenths of a percent, rounded down; null when there
 * is nothing to divide by.
 */
final class PlatformAnalytics
{
    /** The audit log of the page's downloads. */
    public const string AUDIT_LOG = 'analytics';

    /** Leaderboard length, and the invoices a customer needs to be ranked for reliability. */
    public const int LEADERS = 10;

    public const int RELIABILITY_MIN_INVOICES = 3;

    /** @var array<string, array<string, array<string, int>>> metric => dimension => date => value */
    private array $values = [];

    /** @var array<string, true> days with flows */
    private array $flowDays = [];

    /** @var array<string, true> days with stocks */
    private array $stockDays = [];

    /** @var array<string, string>|null */
    private ?array $planNames = null;

    private ?string $latestStocks;

    private ?string $startStocks;

    /** @var array<string, array<string, array<string, int>>> date => metric => dimension => value */
    private array $stocks = [];

    public function __construct(public readonly AnalyticsRange $range)
    {
        $to = $range->to->toDateString();
        $dayBefore = $range->from->subDay()->toDateString();
        // From the month before the range's end, for "this month vs last".
        $loadFrom = min($dayBefore, $range->to->startOfMonth()->subMonthNoOverflow()->toDateString());
        $perOrganization = array_map(static fn (PlatformMetric $metric): string => $metric->value, array_filter(PlatformMetric::cases(), static fn (PlatformMetric $metric): bool => $metric->isPerOrganization()));

        DB::table('daily_platform_metrics')
            ->whereBetween('date', [$loadFrom, $to])
            ->whereNotIn('metric', $perOrganization)
            ->get(['date', 'metric', 'dimension', 'value'])
            ->each(function (object $row): void {
                $date = substr((string) $row->date, 0, 10);

                if ($row->metric === PlatformMetric::Snapshot->value) {
                    $row->dimension === 'stocks' ? $this->stockDays[$date] = true : $this->flowDays[$date] = true;

                    return;
                }

                $this->values[(string) $row->metric][(string) $row->dimension][$date] = (int) $row->value;
            });

        $stockDates = fn () => DB::table('daily_platform_metrics')->where('metric', PlatformMetric::Snapshot->value)->where('dimension', 'stocks');
        $this->latestStocks = self::date($stockDates()->where('date', '<=', $to)->max('date'));
        $this->startStocks = self::date($stockDates()->where('date', '<=', $dayBefore)->max('date'))
            ?? self::date($stockDates()->whereBetween('date', [$range->from->toDateString(), $to])->min('date'));

        foreach (array_unique(array_filter([$this->latestStocks, $this->startStocks])) as $date) {
            $this->stocks[$date] = [];

            DB::table('daily_platform_metrics')
                ->where('date', $date)
                ->whereIn('metric', array_map(static fn (PlatformMetric $metric): string => $metric->value, PlatformMetric::stocks()))
                ->get(['metric', 'dimension', 'value'])
                ->each(function (object $row) use ($date): void {
                    $this->stocks[$date][(string) $row->metric][(string) $row->dimension] = (int) $row->value;
                });
        }
    }

    /** Who opens the page and its exports. */
    public static function visibleTo(?Authenticatable $staff): bool
    {
        return $staff !== null && Gate::forUser($staff)->allows(StaffPermission::AnalyticsView->value);
    }

    public function hasData(): bool
    {
        return $this->latestStocks !== null || $this->takenDays() !== [];
    }

    /** The day the stocks shown were taken for, if any. */
    public function stocksDay(): ?CarbonImmutable
    {
        return $this->latestStocks === null ? null : CarbonImmutable::parse($this->latestStocks, MetricsSnapshot::TIMEZONE);
    }

    /**
     * Days of the range whose flows were taken.
     *
     * @return list<string>
     */
    public function takenDays(): array
    {
        return array_values(array_filter($this->range->days(), fn (string $day): bool => isset($this->flowDays[$day])));
    }

    /** @return list<string> days of the range without figures */
    public function missingDays(): array
    {
        return array_values(array_filter($this->range->days(), fn (string $day): bool => ! isset($this->flowDays[$day])));
    }

    // ---- Reading ----------------------------------------------------------

    /** A stock on the latest day with stocks. */
    public function latest(PlatformMetric $metric, string $dimension = ''): ?int
    {
        return $this->latestStocks === null ? null : ($this->stocks[$this->latestStocks][$metric->value][$dimension] ?? 0);
    }

    /** A stock at the range's start (the day before it, or its first day with stocks). */
    public function start(PlatformMetric $metric, string $dimension = ''): ?int
    {
        return $this->startStocks === null ? null : ($this->stocks[$this->startStocks][$metric->value][$dimension] ?? 0);
    }

    /** @return array<string, int> a stock's dimensions on the latest day, largest first */
    public function latestByDimension(PlatformMetric $metric): array
    {
        $values = $this->latestStocks === null ? [] : ($this->stocks[$this->latestStocks][$metric->value] ?? []);
        unset($values['']);
        arsort($values);

        return $values;
    }

    /** A flow on the range's last day that has figures. */
    public function lastDay(PlatformMetric $metric, string $dimension = ''): ?int
    {
        $values = array_filter($this->daily($metric, $dimension), static fn (?int $value): bool => $value !== null);

        return $values === [] ? null : (int) end($values);
    }

    /** A plan's name by its key (`plans.key`), for the MRR by plan. */
    public function planName(string $key): string
    {
        $this->planNames ??= DB::table('plans')->pluck('name', 'key')->map(static fn (mixed $name): string => (string) $name)->all();

        return $this->planNames[$key] ?? $key;
    }

    /** A flow added up over the range. */
    public function sum(PlatformMetric $metric, string $dimension = ''): int
    {
        return $this->sumBetween($metric, $dimension, $this->range->from->toDateString(), $this->range->to->toDateString());
    }

    /** @return array<string, int> a flow's dimensions added up over the range, largest first */
    public function sumByDimension(PlatformMetric $metric): array
    {
        $sums = [];

        foreach ($this->values[$metric->value] ?? [] as $dimension => $_) {
            if ($dimension !== '') {
                $sums[$dimension] = $this->sum($metric, (string) $dimension);
            }
        }

        arsort($sums);

        return array_filter($sums);
    }

    /**
     * One value per day of the range: a stock where the day has stocks, a
     * flow where the day was taken; null where there is no figure.
     *
     * @return array<string, int|null>
     */
    public function daily(PlatformMetric $metric, string $dimension = ''): array
    {
        $taken = $metric->isStock() ? $this->stockDays : $this->flowDays;
        $series = [];

        foreach ($this->range->days() as $day) {
            $series[$day] = isset($taken[$day]) ? ($this->values[$metric->value][$dimension][$day] ?? 0) : null;
        }

        return $series;
    }

    /**
     * A flow by week (Monday to Sunday, cut to the range), keyed by the
     * week's first day in the range.
     *
     * @return array<string, int>
     */
    public function weekly(PlatformMetric $metric, string $dimension = ''): array
    {
        $weeks = [];

        foreach ($this->daily($metric, $dimension) as $day => $value) {
            $date = CarbonImmutable::parse($day, MetricsSnapshot::TIMEZONE);
            $key = max($date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $this->range->from->toDateString());
            $weeks[$key] = ($weeks[$key] ?? 0) + ($value ?? 0);
        }

        return $weeks;
    }

    /**
     * A flow by Lagos month (cut to the range), keyed YYYY-MM.
     *
     * @return array<string, int>
     */
    public function monthly(PlatformMetric $metric, string $dimension = ''): array
    {
        $months = [];

        foreach ($this->daily($metric, $dimension) as $day => $value) {
            $key = substr($day, 0, 7);
            $months[$key] = ($months[$key] ?? 0) + ($value ?? 0);
        }

        return $months;
    }

    // ---- Revenue ----------------------------------------------------------

    public function arr(): ?int
    {
        $mrr = $this->latest(PlatformMetric::MrrKobo);

        return $mrr === null ? null : $mrr * 12;
    }

    /**
     * Invoiced before VAT in the Lagos month of the range's end, and in the
     * month before it (whole months, whatever the range).
     *
     * @return array{this: int, last: int, thisMonth: CarbonImmutable, lastMonth: CarbonImmutable}
     */
    public function revenueByMonth(): array
    {
        $thisMonth = $this->range->to->startOfMonth();
        $lastMonth = $thisMonth->subMonthNoOverflow();

        return [
            'this' => $this->sumBetween(PlatformMetric::InvoicedNetKobo, '', $thisMonth->toDateString(), $this->range->to->toDateString()),
            'last' => $this->sumBetween(PlatformMetric::InvoicedNetKobo, '', $lastMonth->toDateString(), $lastMonth->endOfMonth()->toDateString()),
            'thisMonth' => $thisMonth,
            'lastMonth' => $lastMonth,
        ];
    }

    /** Money received ÷ invoiced (VAT included) over the range: a cash measure, so it can pass 100%. */
    public function collectionRate(): ?int
    {
        return self::rate($this->sum(PlatformMetric::ReceivedKobo), $this->sum(PlatformMetric::InvoicedKobo));
    }

    /** Average whole days from issue to payment of the invoices paid in the range, in tenths of a day. */
    public function averageDaysToPay(): ?int
    {
        $paid = $this->sum(PlatformMetric::InvoicesPaid);

        return $paid === 0 ? null : intdiv($this->sum(PlatformMetric::DaysToPay) * 10, $paid);
    }

    // ---- Customers --------------------------------------------------------

    /** First payments ÷ trials ended over the range. */
    public function trialConversion(): ?int
    {
        return self::rate($this->sum(PlatformMetric::TrialConversions), $this->sum(PlatformMetric::TrialsEnded));
    }

    /** Paying customers who cancelled in the range ÷ paying customers at its start. */
    public function churnRate(): ?int
    {
        return self::rate($this->sum(PlatformMetric::Cancellations), $this->start(PlatformMetric::PayingCustomers) ?? 0);
    }

    // ---- Leaderboard ------------------------------------------------------

    /**
     * Top customers (platform-covered organizations apart) by units, by
     * units added over the range, and by payment reliability (subscription
     * invoices due in the year to the latest day, paid by their due date).
     *
     * @return array{units: list<LeaderboardEntry>, growth: list<LeaderboardEntry>, reliability: list<LeaderboardEntry>}
     */
    public function leaderboard(): array
    {
        $end = $this->latestStocks === null ? [] : $this->stocks[$this->latestStocks];
        $start = $this->startStocks === null ? [] : $this->stocks[$this->startStocks];
        $units = $end[PlatformMetric::OrgUnits->value] ?? [];
        $unitsBefore = $start[PlatformMetric::OrgUnits->value] ?? [];
        $due = $end[PlatformMetric::OrgInvoicesDue->value] ?? [];
        $onTime = $end[PlatformMetric::OrgInvoicesOnTime->value] ?? [];
        $entry = static fn (string $id): array => [
            'id' => (int) $id,
            'units' => $units[$id] ?? 0,
            'growth' => ($units[$id] ?? 0) - ($unitsBefore[$id] ?? 0),
            'due' => $due[$id] ?? 0,
            'onTime' => $onTime[$id] ?? 0,
        ];

        $byUnits = array_map($entry, array_map('strval', array_keys($units)));
        usort($byUnits, static fn (array $a, array $b): int => [$b['units'], $a['id']] <=> [$a['units'], $b['id']]);

        $byGrowth = array_filter($byUnits, static fn (array $row): bool => $row['growth'] > 0);
        usort($byGrowth, static fn (array $a, array $b): int => [$b['growth'], $b['units'], $a['id']] <=> [$a['growth'], $a['units'], $b['id']]);

        $byReliability = array_filter(array_map($entry, array_map('strval', array_keys($due))), static fn (array $row): bool => $row['due'] >= self::RELIABILITY_MIN_INVOICES);
        usort($byReliability, static fn (array $a, array $b): int => [intdiv($b['onTime'] * 1000, $b['due']), $b['due'], $a['id']] <=> [intdiv($a['onTime'] * 1000, $a['due']), $a['due'], $b['id']]);

        $boards = [
            'units' => array_slice($byUnits, 0, self::LEADERS),
            'growth' => array_slice($byGrowth, 0, self::LEADERS),
            'reliability' => array_slice($byReliability, 0, self::LEADERS),
        ];

        $ids = array_unique(array_merge(...array_map(static fn (array $board): array => array_column($board, 'id'), array_values($boards))));
        $names = $ids === [] ? [] : Customer::query()->withTrashed()->whereKey($ids)->pluck('name', 'id')->all();

        return array_map(static fn (array $board): array => array_map(static fn (array $row): LeaderboardEntry => new LeaderboardEntry(
            organizationId: $row['id'],
            name: (string) ($names[$row['id']] ?? "Organization #{$row['id']}"),
            units: $row['units'],
            growth: $row['growth'],
            invoicesDue: $row['due'],
            paidOnTime: $row['onTime'],
        ), $board), $boards);
    }

    // ---- Helpers ----------------------------------------------------------

    /** A ratio in tenths of a percent, rounded down; null when the denominator is zero. */
    public static function rate(int $part, int $whole): ?int
    {
        return $whole <= 0 ? null : intdiv(max(0, $part) * 1000, $whole);
    }

    /** "89.3%" from tenths of a percent; "—" for null. */
    public static function percent(?int $tenths): string
    {
        return $tenths === null ? '—' : intdiv($tenths, 10).'.'.($tenths % 10).'%';
    }

    private function sumBetween(PlatformMetric $metric, string $dimension, string $from, string $to): int
    {
        $sum = 0;

        foreach ($this->values[$metric->value][$dimension] ?? [] as $day => $value) {
            if ($day >= $from && $day <= $to) {
                $sum += $value;
            }
        }

        return $sum;
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
