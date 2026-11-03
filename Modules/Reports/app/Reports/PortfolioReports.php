<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Billing\Contracts\BillReports;
use Modules\Billing\Contracts\ReportBill;
use Modules\Expenses\Contracts\ExpenseTotal;
use Modules\Expenses\Contracts\ExpenseTotals;
use Modules\Leasing\Contracts\EndingLease;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Payments\Contracts\CollectedAmount;
use Modules\Payments\Contracts\OwnerCollections;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\BuildingOccupancy;
use Modules\Properties\Contracts\BuildingUnits;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitCounts;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Reports\Enums\AgingBucket;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Works out the customer dashboards (P08-01) from the live records, inside
 * the current organization, through the other modules' contracts only
 * (ARCHITECTURE.md § 8 rule 3):
 *
 * - occupancy: Properties' BuildingOccupancy (units by status now);
 * - collections vs billed: Billing's BillReports (bills issued in the
 *   range, void ones left out) and Payments' OwnerCollections (money that
 *   settled bills, by the moment it counts, as the owner statements count
 *   it: refunds of credit netted, voided receipts negative);
 * - arrears aging: Billing's BillReports::open() aged against today;
 * - upcoming renewals: Leasing's OwnerLeases::endingBetween() with the
 *   tenants' names (Tenants' TenantDirectory) and the units' labels
 *   (Properties' UnitDirectory);
 * - expenses by category: Expenses' ExpenseTotals;
 * - per-building performance: all of the above by building.
 *
 * Each part reads its sources once; all() shares them between the parts,
 * so the whole page costs the same few queries as its largest part. Money
 * in kobo (App\Support\Money), days in Lagos. Reads only; the caller
 * checks the policy (ReportPolicy). See docs/plans/P08-subtasks.md.
 */
final class PortfolioReports
{
    /** @var array<string, mixed> what this instance has read already, per scope and filters */
    private array $read = [];

    public function __construct(
        private readonly BillReports $bills,
        private readonly OwnerCollections $collections,
        private readonly ExpenseTotals $expenseTotals,
        private readonly OwnerLeases $leases,
        private readonly BuildingOccupancy $occupancy,
        private readonly PropertyDirectory $properties,
        private readonly UnitDirectory $units,
        private readonly TenantDirectory $tenants,
        private readonly BuildingAccess $access,
    ) {}

    public function scope(User $user, ReportFilters $filters): ReportScope
    {
        return ReportScope::for($user, $filters, $this->access);
    }

    public function all(User $user, ReportFilters $filters): PortfolioReport
    {
        $scope = $this->scope($user, $filters);
        $buildingName = $filters->buildingId === null ? null : ($this->properties->find($filters->buildingId)->name ?? null);

        return new PortfolioReport(
            filters: $filters,
            scope: $scope,
            buildingName: $buildingName,
            occupancy: $this->occupancy($scope),
            collections: $this->collections($scope, $filters),
            arrears: $this->arrears($scope, $filters),
            renewals: $this->renewals($scope, $filters),
            expenses: $this->expenses($scope, $filters),
            buildings: $this->buildings($scope, $filters),
        );
    }

    public function occupancy(ReportScope $scope): OccupancyReport
    {
        $buildings = $this->activeBuildings($scope);
        $totals = [];

        foreach ($buildings as $building) {
            foreach ($building->units->byStatus() as $status => $count) {
                $totals[$status] = ($totals[$status] ?? 0) + $count;
            }
        }

        return new OccupancyReport($buildings, UnitCounts::fromStatuses($totals));
    }

    public function collections(ReportScope $scope, ReportFilters $filters): CollectionsReport
    {
        $flows = $this->flows($scope, $filters);
        $months = [];
        $totals = FlowTotals::empty();

        foreach ($filters->months() as $month) {
            $monthTotals = $flows['months'][$month->key] ?? FlowTotals::empty();
            $months[] = new CollectionsMonth($month, $monthTotals);
            $totals = $totals->plus($monthTotals);
        }

        return new CollectionsReport($months, $totals);
    }

    public function arrears(ReportScope $scope, ReportFilters $filters): ArrearsReport
    {
        $today = $filters->today;
        $lines = [];
        $buckets = array_fill_keys(array_map(static fn (AgingBucket $bucket): string => $bucket->value, AgingBucket::cases()), Money::zero());
        $counts = array_fill_keys(array_keys($buckets), 0);
        $byBuilding = [];
        $notYetDue = Money::zero();
        $notYetDueCount = 0;
        $open = $this->openBills($scope);
        $names = $this->buildingNames(array_map(static fn (ReportBill $bill): ?int => $bill->propertyId, $open), $scope);

        foreach ($open as $bill) {
            if (! $bill->outstanding->isPositive()) {
                continue;
            }

            $days = self::daysBetween($bill->dueDate, $today);

            if ($days < 0) {
                $notYetDue = $notYetDue->plus($bill->outstanding);
                $notYetDueCount++;

                continue;
            }

            $bucket = AgingBucket::forDays($days);
            $buckets[$bucket->value] = $buckets[$bucket->value]->plus($bill->outstanding);
            $counts[$bucket->value]++;
            $key = $bill->propertyId ?? 0;
            $byBuilding[$key][$bucket->value] = ($byBuilding[$key][$bucket->value] ?? Money::zero())->plus($bill->outstanding);
            $lines[] = new ArrearsLine($bill, $days, $bucket, $names[$key] ?? self::noBuilding());
        }

        return new ArrearsReport($today, $lines, $buckets, $counts, $byBuilding, $notYetDue, $notYetDueCount, $names);
    }

    public function renewals(ReportScope $scope, ReportFilters $filters): RenewalsReport
    {
        $from = $filters->today;
        $until = $filters->renewalsUntil();
        $buildingIds = $scope->propertyIds ?? array_map(static fn (BuildingUnits $building): int => $building->propertyId, $this->activeBuildings($scope));
        $ending = $buildingIds === [] ? [] : $this->leases->endingBetween($buildingIds, $from, $until);

        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (EndingLease $lease): int => $lease->lease->tenantProfileId, $ending))));
        $units = $this->units->findMany(array_values(array_unique(array_map(static fn (EndingLease $lease): int => $lease->lease->unitId, $ending))));
        $names = $this->buildingNames(array_map(static fn (EndingLease $lease): int => $lease->lease->propertyId, $ending), $scope);

        $lines = array_map(fn (EndingLease $lease): RenewalLine => new RenewalLine(
            leaseId: $lease->lease->id,
            contractNumber: $lease->lease->contractNumber,
            tenantName: $tenants[$lease->lease->tenantProfileId]->name ?? self::text('Unknown tenant'),
            buildingName: $names[$lease->lease->propertyId] ?? self::noBuilding(),
            unitLabel: $units[$lease->lease->unitId]->label ?? '',
            endDate: $lease->lease->endDate,
            daysLeft: self::daysBetween($from, $lease->lease->endDate),
            rent: $lease->lease->rent,
            cycleLabel: $lease->lease->cycle->label(),
            renewalState: $lease->renewalState(),
            renewalLabel: $lease->renewalStateLabel(),
        ), $ending);

        return new RenewalsReport($from, $until, $filters->renewalDays, $lines);
    }

    public function expenses(ReportScope $scope, ReportFilters $filters): ExpensesReport
    {
        /** @var array<string, array{string, int, Money, Money}> $categories category => [label, count, buildings, organization] */
        $categories = [];

        foreach ($this->expenseTotals($scope, $filters) as $total) {
            $line = $categories[$total->category] ?? [$total->categoryLabel, 0, Money::zero(), Money::zero()];
            $line[1] += $total->count;

            if ($total->propertyId === null) {
                $line[3] = $line[3]->plus($total->amount);
            } else {
                $line[2] = $line[2]->plus($total->amount);
            }

            $categories[$total->category] = $line;
        }

        $lines = [];

        foreach ($categories as $category => [$label, $count, $buildings, $organization]) {
            $lines[] = new ExpenseCategoryLine($category, $label, $count, $buildings, $organization);
        }

        usort($lines, static fn (ExpenseCategoryLine $a, ExpenseCategoryLine $b): int => [$b->total()->kobo, $a->label] <=> [$a->total()->kobo, $b->label]);

        return new ExpensesReport($lines, $scope->everything());
    }

    public function buildings(ReportScope $scope, ReportFilters $filters): BuildingsReport
    {
        $flows = $this->flows($scope, $filters)['buildings'];
        $arrears = $this->arrears($scope, $filters);
        /** @var array<int, Money> $expenses property id (0: none) => spent */
        $expenses = [];

        foreach ($this->expenseTotals($scope, $filters) as $total) {
            $key = $total->propertyId ?? 0;
            $expenses[$key] = ($expenses[$key] ?? Money::zero())->plus($total->amount);
        }

        $active = [];

        foreach ($this->activeBuildings($scope) as $building) {
            $active[$building->propertyId] = $building;
        }

        $others = array_values(array_diff(array_unique([...array_keys($flows), ...array_keys($arrears->byBuilding), ...array_keys($expenses)]), [0, ...array_keys($active)]));
        $otherBuildings = $this->properties->findMany($others);
        $lines = [];

        foreach ($active as $id => $building) {
            $lines[] = new BuildingLine($id, $building->name, true, $building->units, $flows[$id] ?? FlowTotals::empty(), $arrears->owedBy($id), $expenses[$id] ?? Money::zero());
        }

        foreach ($others as $id) {
            $lines[] = new BuildingLine($id, $otherBuildings[$id]->name ?? self::text('A building no longer here'), false, new UnitCounts, $flows[$id] ?? FlowTotals::empty(), $arrears->owedBy($id), $expenses[$id] ?? Money::zero());
        }

        usort($lines, static fn (BuildingLine $a, BuildingLine $b): int => strnatcasecmp($a->name, $b->name) ?: ($a->propertyId <=> $b->propertyId));

        $none = new BuildingLine(null, self::noBuilding(), false, new UnitCounts, $flows[0] ?? FlowTotals::empty(), $arrears->owedBy(null), $expenses[0] ?? Money::zero());

        if ($scope->everything() && (! $none->expenses->isZero() || ! $none->arrears->isZero() || ! $none->flows->billedTotal()->isZero() || ! $none->flows->collectedTotal()->isZero())) {
            $lines[] = $none;
        }

        return new BuildingsReport($lines);
    }

    /**
     * Billed and collected in the range, by month and by building (0: no
     * building), read once per scope and filters.
     *
     * @return array{months: array<string, FlowTotals>, buildings: array<int, FlowTotals>}
     */
    private function flows(ReportScope $scope, ReportFilters $filters): array
    {
        $key = 'flows:'.self::key($scope, $filters);

        if (isset($this->read[$key])) {
            /** @var array{months: array<string, FlowTotals>, buildings: array<int, FlowTotals>} */
            return $this->read[$key];
        }

        $months = $filters->months();
        $byMonth = [];
        $byBuilding = [];

        foreach ($this->bills->issuedBetween($filters->start(), $filters->end(), $scope->propertyIds) as $bill) {
            if ($bill->isVoid()) {
                continue;
            }

            $group = FlowTotals::groupOf($bill->type);
            $month = self::monthOf($months, $bill->issuedAt);
            $building = $bill->propertyId ?? 0;

            if ($month !== null) {
                $byMonth[$month] = ($byMonth[$month] ?? FlowTotals::empty())->addBilled($group, $bill->amount);
            }

            $byBuilding[$building] = ($byBuilding[$building] ?? FlowTotals::empty())->addBilled($group, $bill->amount);
        }

        $amounts = $this->collections->between($filters->start(), $filters->end());
        $bills = $amounts === [] ? [] : $this->bills->findMany(array_values(array_unique(array_map(static fn (CollectedAmount $amount): int => $amount->billId, $amounts))));

        foreach ($amounts as $amount) {
            $bill = $bills[$amount->billId] ?? null;

            if ($bill === null || ! $scope->covers($bill->propertyId)) {
                continue;
            }

            $group = FlowTotals::groupOf($bill->type);
            $month = self::monthOf($months, $amount->at);
            $building = $bill->propertyId ?? 0;

            if ($month !== null) {
                $byMonth[$month] = ($byMonth[$month] ?? FlowTotals::empty())->addCollected($group, $amount->amount);
            }

            $byBuilding[$building] = ($byBuilding[$building] ?? FlowTotals::empty())->addCollected($group, $amount->amount);
        }

        return $this->read[$key] = ['months' => $byMonth, 'buildings' => $byBuilding];
    }

    /** @return list<BuildingUnits> */
    private function activeBuildings(ReportScope $scope): array
    {
        $key = 'buildings:'.json_encode($scope->propertyIds);

        /** @var list<BuildingUnits> */
        return $this->read[$key] ??= $this->occupancy->active($scope->propertyIds);
    }

    /** @return list<ReportBill> */
    private function openBills(ReportScope $scope): array
    {
        $key = 'open:'.json_encode($scope->propertyIds);

        /** @var list<ReportBill> */
        return $this->read[$key] ??= $this->bills->open($scope->propertyIds);
    }

    /** @return list<ExpenseTotal> */
    private function expenseTotals(ReportScope $scope, ReportFilters $filters): array
    {
        $key = 'expenses:'.self::key($scope, $filters);

        /** @var list<ExpenseTotal> */
        return $this->read[$key] ??= $this->expenseTotals->between($filters->from, $filters->to, $scope->propertyIds);
    }

    /**
     * Building names by id (0: no building), active ones from the
     * occupancy read, others looked up once.
     *
     * @param  list<int|null>  $propertyIds
     * @return array<int, string>
     */
    private function buildingNames(array $propertyIds, ReportScope $scope): array
    {
        $names = [0 => self::noBuilding()];

        foreach ($this->activeBuildings($scope) as $building) {
            $names[$building->propertyId] = $building->name;
        }

        $missing = array_values(array_diff(array_unique(array_filter($propertyIds, static fn (?int $id): bool => $id !== null)), array_keys($names)));

        foreach ($this->properties->findMany($missing) as $id => $property) {
            $names[$id] = $property->name;
        }

        return $names;
    }

    /** @param  list<ReportMonth>  $months */
    private static function monthOf(array $months, CarbonImmutable $at): ?string
    {
        foreach ($months as $month) {
            if ($month->contains($at)) {
                return $month->key;
            }
        }

        return null;
    }

    /** Whole calendar days from $from to $to (negative when $to is earlier), by their dates. */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $end = new DateTimeImmutable($to->format('Y-m-d'), $utc);
        $days = (int) $start->diff($end)->days;

        return $end < $start ? -$days : $days;
    }

    private static function key(ReportScope $scope, ReportFilters $filters): string
    {
        return json_encode([$scope->propertyIds, $filters->from->toDateString(), $filters->to->toDateString()]) ?: '';
    }

    private static function noBuilding(): string
    {
        return self::text('Not linked to a building');
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
