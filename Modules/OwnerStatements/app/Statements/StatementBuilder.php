<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Modules\Billing\Contracts\OwnerBill;
use Modules\Billing\Contracts\OwnerBills;
use Modules\Expenses\Contracts\ChargedExpense;
use Modules\Expenses\Contracts\OwnerChargedExpenses;
use Modules\Payments\Contracts\CollectedAmount;
use Modules\Payments\Contracts\OwnerCollections;
use Modules\Properties\Contracts\OwnerProfileDirectory;
use Modules\Properties\Contracts\PropertySummary;

/**
 * Works out owners' statements from the live records (P08-05;
 * BILLING_PAYMENTS.md § 7.9), inside the managing organization, through
 * the other modules' contracts only (ARCHITECTURE.md § 8 rule 3):
 *
 * - the owner's buildings (Properties' OwnerProfileDirectory, archived
 *   ones too);
 * - money collected for those buildings' bills, by the moment it counts
 *   (Payments' OwnerCollections: a payment when it was confirmed, tenant
 *   credit when it was used for a bill, a voided receipt negative when it
 *   was voided), split by the bill's type (Billing's OwnerBills): utility
 *   bills are utilities; rent, opening-balance and other bills are rent;
 * - the bills issued in the month (void ones listed, as void; they hold no
 *   money: a bill with payments cannot be voided);
 * - the expenses charged to the owner (Expenses' OwnerChargedExpenses), by
 *   the day they were paid;
 * - the owner's terms (StatementTermsBook) for the commission and whether
 *   utilities count (StatementFigures).
 *
 * Months are Lagos months (StatementMonth). Several months are worked out
 * from one read of each source, so a list of months costs the same few
 * queries as one month. Reads only.
 */
final readonly class StatementBuilder
{
    public function __construct(
        private OwnerProfileDirectory $owners,
        private OwnerBills $bills,
        private OwnerCollections $collections,
        private OwnerChargedExpenses $expenses,
        private StatementTermsBook $terms,
    ) {}

    /**
     * The owner's statements for these months, worked out now.
     *
     * @param  list<StatementMonth>  $months
     * @return array<string, OwnerStatement> by month key
     */
    public function build(int $ownerProfileId, array $months): array
    {
        if ($months === []) {
            return [];
        }

        $owner = $this->owners->find($ownerProfileId) ?? throw new \LogicException('No such owner here.');
        $terms = $this->terms->of($ownerProfileId);
        $buildings = $this->owners->buildingsOf($ownerProfileId);
        $buildingIds = array_map(static fn (PropertySummary $building): int => $building->id, $buildings);

        usort($months, static fn (StatementMonth $a, StatementMonth $b): int => [$a->year, $a->month] <=> [$b->year, $b->month]);
        $first = $months[0];
        $last = $months[count($months) - 1];

        /** @var array<string, array<int, list<array{CollectedAmount, OwnerBill}>>> $collected month => building => amounts */
        $collected = [];
        /** @var array<string, array<int, list<OwnerBill>>> $issued */
        $issued = [];
        /** @var array<string, array<int, list<ChargedExpense>>> $charged */
        $charged = [];

        if ($buildingIds !== []) {
            $amounts = $this->collections->between($first->start(), $last->end());
            $bills = $amounts === [] ? [] : $this->bills->findMany(array_values(array_unique(array_map(static fn (CollectedAmount $amount): int => $amount->billId, $amounts))));

            foreach ($amounts as $amount) {
                $bill = $bills[$amount->billId] ?? null;

                if ($bill !== null && $bill->propertyId !== null && in_array($bill->propertyId, $buildingIds, true)) {
                    $collected[StatementMonth::containing($amount->at)->key()][$bill->propertyId][] = [$amount, $bill];
                }
            }

            foreach ($this->bills->issuedBetween($buildingIds, $first->start(), $last->end()) as $bill) {
                if ($bill->propertyId !== null) {
                    $issued[StatementMonth::containing($bill->issuedAt)->key()][$bill->propertyId][] = $bill;
                }
            }

            foreach ($this->expenses->between($buildingIds, $first->firstDay(), $last->lastDay()) as $expense) {
                $charged[StatementMonth::of($expense->spentOn->year, $expense->spentOn->month)->key()][$expense->propertyId][] = $expense;
            }
        }

        $statements = [];

        foreach ($months as $month) {
            $key = $month->key();
            $parts = array_map(fn (PropertySummary $building): BuildingStatement => $this->building(
                $building,
                $collected[$key][$building->id] ?? [],
                $issued[$key][$building->id] ?? [],
                $charged[$key][$building->id] ?? [],
                $terms,
            ), $buildings);

            $statements[$key] = new OwnerStatement(
                organizationId: $owner->organizationId,
                ownerProfileId: $owner->id,
                ownerName: $owner->name,
                month: $month,
                commissionBps: $terms->commissionBps,
                utilitiesToOwner: $terms->utilitiesToOwner,
                buildings: $parts,
                totals: StatementFigures::sum(array_map(static fn (BuildingStatement $part): StatementFigures => $part->figures, $parts), $terms->utilitiesToOwner),
            );
        }

        return $statements;
    }

    /**
     * @param  list<array{CollectedAmount, OwnerBill}>  $collected
     * @param  list<OwnerBill>  $issued
     * @param  list<ChargedExpense>  $charged
     */
    private function building(PropertySummary $building, array $collected, array $issued, array $charged, StatementTerms $terms): BuildingStatement
    {
        $rent = Money::zero();
        $utilities = Money::zero();
        /** @var array<string, array{Money, Money}> $units */
        $units = [];
        $lines = [];

        foreach ($collected as [$amount, $bill]) {
            $utility = $bill->type === 'utility';
            $unit = $bill->unit ?? '';
            $units[$unit] ??= [Money::zero(), Money::zero()];

            if ($utility) {
                $utilities = $utilities->plus($amount->amount);
                $units[$unit][1] = $units[$unit][1]->plus($amount->amount);
            } else {
                $rent = $rent->plus($amount->amount);
                $units[$unit][0] = $units[$unit][0]->plus($amount->amount);
            }

            $lines[] = new CollectionLine(
                at: $amount->at,
                kind: $amount->kind->value,
                kindLabel: $amount->kind->label(),
                billNumber: $bill->number,
                typeLabel: $bill->typeLabel,
                utility: $utility,
                unit: $unit,
                tenantName: $bill->tenantName ?? '',
                receiptNumber: $amount->receiptNumber,
                amount: $amount->amount,
            );
        }

        uksort($units, static fn (int|string $a, int|string $b): int => strnatcasecmp((string) $a, (string) $b));
        $expenses = Money::sum(array_map(static fn (ChargedExpense $expense): Money => $expense->amount, $charged));

        return new BuildingStatement(
            propertyId: $building->id,
            name: $building->name,
            archived: $building->archived,
            figures: StatementFigures::work($rent, $utilities, $expenses, $terms->commissionBps, $terms->utilitiesToOwner),
            units: array_map(static fn (int|string $unit, array $money): UnitLine => new UnitLine((string) $unit, $money[0], $money[1]), array_keys($units), array_values($units)),
            collections: $lines,
            bills: array_map(static fn (OwnerBill $bill): BillLine => new BillLine(
                number: $bill->number,
                typeLabel: $bill->typeLabel,
                unit: $bill->unit ?? '',
                tenantName: $bill->tenantName ?? '',
                issuedAt: $bill->issuedAt,
                amount: $bill->amount,
                status: $bill->status,
                statusLabel: $bill->statusLabel,
                statusColor: $bill->statusColor,
            ), $issued),
            expenses: array_map(static fn (ChargedExpense $expense): ExpenseLine => new ExpenseLine(
                spentOn: $expense->spentOn,
                categoryLabel: $expense->categoryLabel,
                description: $expense->description,
                unit: $expense->unitLabel ?? '',
                amount: $expense->amount,
            ), $charged),
        );
    }
}
