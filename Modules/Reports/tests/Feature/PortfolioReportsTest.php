<?php

declare(strict_types=1);

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Expenses\Recording\ExpenseBook;
use Modules\Leasing\Leases\LeaseService;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;

require_once __DIR__.'/report-world.php';

/*
 * P08-01 AC "every report has a feature test with seeded edge data":
 * occupancy, upcoming renewals, expenses by category and per-building
 * performance, on the hand-computed fixture (report-world.php; the clock
 * at 26 Jan 2027, 10:00) with the edge cases each adds. Collections vs
 * billed and arrears aging have their own files.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
});

afterEach(fn () => exportsFreshRequest());

it('counts occupancy now, building by building, without archived units or buildings', function () {
    // Office Block gets a unit under maintenance; Palm Court an archived unit; an archived building with a let unit.
    unitIn($this->acme, $this->office, ['unit_label' => 'Suite A', 'status' => UnitStatus::UnderMaintenance]);
    $archivedUnit = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 9Z', 'status' => UnitStatus::Occupied]);
    inOrganization($this->acme, fn () => Unit::query()->whereKey($archivedUnit->id)->sole()->delete());
    $old = buildingIn($this->acme, ['name' => 'Old Yard', 'slug' => 'old-yard']);
    unitIn($this->acme, $old, ['unit_label' => 'Shop 1', 'status' => UnitStatus::Occupied]);
    inOrganization($this->acme, fn () => Property::query()->whereKey($old->id)->sole()->forceFill(['status' => PropertyStatus::Archived])->save());

    $occupancy = reportOf($this->acme, $this->owner)->occupancy;

    // Cedar House 1/1, Marina Heights 2/2, Office Block 0/1 (under maintenance), Palm Court 1/2.
    expect(array_map(fn ($building) => [$building->name, $building->units->total(), $building->units->occupied, $building->units->occupancyPercent()], $occupancy->buildings))
        ->toBe([['Cedar House', 1, 1, 100], ['Marina Heights', 2, 2, 100], ['Office Block', 1, 0, 0], ['Palm Court', 2, 1, 50]])
        ->and([$occupancy->totals->total(), $occupancy->totals->occupied, $occupancy->totals->vacant, $occupancy->totals->underMaintenance, $occupancy->totals->occupancyPercent()])->toBe([6, 4, 1, 1, 66]);

    // One building, and the other organization's own.
    expect(array_map(fn ($building) => $building->name, reportOf($this->acme, $this->owner, ['building' => (string) $this->palm->id])->occupancy->buildings))->toBe(['Palm Court'])
        ->and(array_map(fn ($building) => $building->name, reportOf($this->other, $this->otherOwner)->occupancy->buildings))->toBe(['Harbour Point', 'Lagoon View']);

    expect(reportRows($this->owner, 'report_occupancy'))->toBe([
        ['Building', 'Units', 'Occupied', 'Vacant', 'Reserved', 'Under maintenance', 'Unavailable', 'Occupancy (%)'],
        ['Cedar House', '1', '1', '0', '0', '0', '0', '100'],
        ['Marina Heights', '2', '2', '0', '0', '0', '0', '100'],
        ['Office Block', '1', '0', '0', '0', '1', '0', '0'],
        ['Palm Court', '2', '1', '1', '0', '0', '0', '50'],
    ]);
    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-occupancy">66%', false);
});

it('lists the active leases ending in the next 90, 60 or 30 days with where each renewal stands', function () {
    // Leases at Lekki Gardens that started last year, paid up front (no bills): ending in 15, 33, 79 and 94 days.
    $lekki = buildingIn($this->acme, ['name' => 'Lekki Gardens', 'slug' => 'lekki-gardens']);
    $lease = fn (string $unit, string $first, string $last, string $phone, string $start, string $end, array $changes = []) => activeLease(
        $this->acme,
        $this->owner,
        unitIn($this->acme, $lekki, ['unit_label' => $unit]),
        tenantIn($this->acme, ['first_name' => $first, 'last_name' => $last, 'phone' => $phone]),
        $changes + ['startDate' => $start, 'endDate' => $end, 'paidUntil' => $end, 'rent' => '1,200,000', 'deposit' => '0'],
    );
    $drafted = $lease('Flat 1', 'Amaka', 'Nwosu', '+2348031230011', '2026-02-11', '2027-02-10');
    $soon = $lease('Flat 2', 'Bayo', 'Ojo', '+2348031230012', '2026-03-01', '2027-02-28');
    $lease('Flat 3', 'Chidinma', 'Eke', '+2348031230013', '2026-04-16', '2027-04-15', ['autoRenew' => true]);
    $lease('Flat 4', 'Dapo', 'Ade', '+2348031230014', '2026-05-01', '2027-04-30');
    // Amaka's renewal is drafted (it waits to start).
    inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $drafted, leaseInput(['startDate' => '2027-02-11', 'endDate' => '2028-02-10', 'rent' => '1,350,000', 'deposit' => '0'])));
    // Bello's lease ending soon is the other organization's.
    activeLease($this->other, $this->otherOwner, unitIn($this->other, $this->harbour, ['unit_label' => 'Suite 10']), tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Soon', 'phone' => '+2348035550011']), ['startDate' => '2026-03-01', 'endDate' => '2027-02-28', 'paidUntil' => '2027-02-28']);

    $renewals = reportOf($this->acme, $this->owner)->renewals;
    expect($renewals->days)->toBe(90)
        ->and($renewals->until->toDateString())->toBe('2027-04-26')
        ->and(array_map(fn ($line) => [$line->tenantName, $line->unitLabel, $line->buildingName, $line->endDate->toDateString(), $line->daysLeft, $line->renewalState], $renewals->lines))->toBe([
            ['Amaka Nwosu', 'Flat 1', 'Lekki Gardens', '2027-02-10', 15, 'renewal_drafted'],
            ['Bayo Ojo', 'Flat 2', 'Lekki Gardens', '2027-02-28', 33, 'not_renewed'],
            ['Chidinma Eke', 'Flat 3', 'Lekki Gardens', '2027-04-15', 79, 'auto_renews'],
        ])
        ->and($renewals->notRenewedCount())->toBe(1);

    expect(array_map(fn ($line) => $line->leaseId, reportOf($this->acme, $this->owner, ['days' => '60'])->renewals->lines))->toBe([$drafted->id, $soon->id])
        ->and(array_map(fn ($line) => $line->leaseId, reportOf($this->acme, $this->owner, ['days' => '30'])->renewals->lines))->toBe([$drafted->id])
        // Any other window is the default 90 days.
        ->and(reportOf($this->acme, $this->owner, ['days' => '45'])->renewals->days)->toBe(90)
        ->and(reportOf($this->acme, $this->owner, ['building' => (string) $this->marina->id])->renewals->lines)->toBe([]);

    $rows = reportRows($this->owner, 'report_renewals');
    expect($rows[0])->toBe(['Tenant', 'Building', 'Unit', 'Lease', 'Ends on', 'Days left', 'Rent', 'Billing cycle', 'Renewal'])
        ->and(array_map(fn ($row) => [$row[0], $row[4], $row[5], $row[6], $row[8]], array_slice($rows, 1)))->toBe([
            ['Amaka Nwosu', '2027-02-10', '15', '1200000.00', 'Renewal drafted'],
            ['Bayo Ojo', '2027-02-28', '33', '1200000.00', 'Not renewed yet'],
            ['Chidinma Eke', '2027-04-15', '79', '1200000.00', 'Rolls on (auto-renew)'],
        ]);
    reportsPage($this->owner)->assertOk()->assertSeeInOrder(['Upcoming renewals', 'Amaka Nwosu', 'Renewal drafted', 'Bayo Ojo', 'Not renewed yet', 'Chidinma Eke'])->assertDontSee('Dapo Ade');
});

it('sums expenses by category: archived ones and other dates left out, the organization\'s own only for every building', function () {
    // The world's ₦40,000 (Marina, charged to the owner) and ₦60,000 (Palm Court) repairs, both in January.
    recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->marina->id, 'category' => 'utilities', 'description' => 'Diesel for the generator', 'amount' => '35,000', 'spentOn' => '2027-01-16']);
    recordExpense($this->acme, $this->owner, ['category' => 'staff', 'description' => 'Office assistant, January', 'amount' => '90,000', 'spentOn' => '2027-01-15']);
    recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Paid in December', 'amount' => '70,000', 'spentOn' => '2026-12-31']);
    $archived = recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Recorded twice', 'amount' => '60,000', 'spentOn' => '2027-01-14']);
    inOrganization($this->acme, fn () => app(ExpenseBook::class)->archive($this->owner, $archived, 'Recorded twice by mistake'));
    recordExpense($this->other, $this->otherOwner, ['propertyId' => (string) $this->lagoon->id, 'description' => 'Bello\'s own', 'amount' => '500,000', 'spentOn' => '2027-01-10']);

    $expenses = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26'])->expenses;
    expect(array_map(fn ($line) => [$line->label, $line->count, $line->buildings->kobo, $line->organization->kobo, $line->total()->kobo], $expenses->categories))->toBe([
        ['Repairs and maintenance', 2, 10_000_000, 0, 10_000_000],
        ['Staff', 1, 0, 9_000_000, 9_000_000],
        ['Utilities', 1, 3_500_000, 0, 3_500_000],
    ])
        ->and($expenses->total()->kobo)->toBe(22_500_000)
        ->and($expenses->count())->toBe(4)
        ->and($expenses->includesOrganization)->toBeTrue();

    // One building: its own expenses, never the organization's.
    $marina = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26', 'building' => (string) $this->marina->id])->expenses;
    expect(array_map(fn ($line) => [$line->label, $line->total()->kobo], $marina->categories))->toBe([['Repairs and maintenance', 4_000_000], ['Utilities', 3_500_000]])
        ->and($marina->includesOrganization)->toBeFalse();

    // December and January together take the ₦70,000.
    expect(reportOf($this->acme, $this->owner, ['from' => '2026-12-01', 'to' => '2027-01-26'])->expenses->total()->kobo)->toBe(29_500_000);

    expect(reportRows($this->owner, 'report_expenses', 'from=2027-01-01&to=2027-01-26'))->toBe([
        ['Category', 'Expenses', 'On buildings', 'Organization\'s own', 'Total'],
        ['Repairs and maintenance', '2', '100000.00', '0.00', '100000.00'],
        ['Staff', '1', '0.00', '90000.00', '90000.00'],
        ['Utilities', '1', '35000.00', '0.00', '35000.00'],
    ]);
});

it('shows each building\'s performance, and the organization\'s own money on a line of its own', function () {
    recordExpense($this->acme, $this->owner, ['category' => 'staff', 'description' => 'Office assistant, January', 'amount' => '90,000', 'spentOn' => '2027-01-15']);

    $report = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26']);
    $lines = array_map(fn ($line) => [
        $line->name,
        $line->units->occupancyPercent(),
        $line->flows->billedTotal()->kobo,
        $line->flows->collectedTotal()->kobo,
        $line->flows->rate()?->label(),
        $line->arrears->kobo,
        $line->expenses->kobo,
        $line->net()->kobo,
    ], $report->buildings->lines);

    // Marina Heights: February's rents issued on 25 Jan (₦350,000; the void levy left out), ₦295,000 collected,
    // Bisi's ₦100,000 of January's rent owed past due, the ₦40,000 repair.
    expect($lines)->toBe([
        ['Cedar House', 100, 0, 180_000_000, null, 0, 0, 180_000_000],
        ['Marina Heights', 100, 35_000_000, 29_500_000, '84.2%', 10_000_000, 4_000_000, 25_500_000],
        ['Office Block', 0, 0, 0, null, 0, 0, 0],
        ['Palm Court', 50, 0, 150_000_000, null, 0, 6_000_000, 144_000_000],
        ['Not linked to a building', 0, 0, 0, null, 0, 9_000_000, -9_000_000],
    ]);

    // The arrears on 26 Jan: Bisi's January rent (25 days past due); February's bills not yet due.
    expect($report->arrears->total()->kobo)->toBe(10_000_000)
        ->and($report->arrears->counts['0_30'])->toBe(1)
        ->and($report->arrears->notYetDue->kobo)->toBe(33_000_000);

    // One building: no line for the organization's own.
    expect(array_map(fn ($line) => $line->name, reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26', 'building' => (string) $this->palm->id])->buildings->lines))->toBe(['Palm Court']);

    $rows = reportRows($this->owner, 'report_buildings', 'from=2027-01-01&to=2027-01-26');
    expect($rows[0])->toBe(['Building', 'Units', 'Occupied', 'Occupancy (%)', 'Billed', 'Collected', 'Collection rate', 'Owed past due (today)', 'Expenses', 'Collected less expenses'])
        ->and($rows[2])->toBe(['Marina Heights', '2', '2', '100', '350000.00', '295000.00', '84.2%', '100000.00', '40000.00', '255000.00'])
        ->and($rows[5])->toBe(['Not linked to a building', '0', '0', '0', '0.00', '0.00', '—', '0.00', '90000.00', '-90000.00']);
});

it('keeps a building that left (archived) on its line while it has money in the range', function () {
    // Cedar House is archived after its money was collected: it still shows for January, marked, with no units.
    inOrganization($this->acme, fn () => DB::table('properties')->where('id', $this->cedar->id)->update(['status' => PropertyStatus::Archived->value]));

    $lines = reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26'])->buildings->lines;
    $cedar = collect($lines)->firstWhere('propertyId', $this->cedar->id);

    expect($cedar->active)->toBeFalse()
        ->and($cedar->units->total())->toBe(0)
        ->and($cedar->flows->collectedTotal()->kobo)->toBe(180_000_000)
        ->and(array_map(fn ($line) => $line->name, reportOf($this->acme, $this->owner)->occupancy->buildings))->not->toContain('Cedar House');
    expect(Money::sum(array_map(fn ($line) => $line->flows->collectedTotal(), $lines))->kobo)->toBe(reportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-26'])->collections->totals->collectedTotal()->kobo);
});
