<?php

declare(strict_types=1);

use App\Dashboards\DashboardGenerations;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Bills\BillService;
use Modules\Leasing\Leases\LeaseService;
use Modules\Payments\Credit\TenantCredit;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Units\Units;
use Modules\Reports\Reports\PortfolioReport;
use Modules\Reports\Reports\PortfolioReports;
use Modules\Reports\Reports\ReportFilters;

require_once __DIR__.'/report-world.php';
require_once __DIR__.'/../../../Properties/tests/Feature/Assignments/helpers.php';

/*
 * P08-04 AC "Dashboards load from cache; cache invalidates on relevant
 * writes" (docs/plans/P08-subtasks.md, defaults 50–69): the Reports page,
 * its exports and its PDF read their figures from the cache, so a second
 * load runs none of the reports' queries; each relevant write (a bill
 * issued or voided, a payment confirmed, a receipt voided, credit refunded,
 * an expense, a unit, a lease) makes the next load show the new figure;
 * an entry never serves another organization or a member who sees other
 * buildings; "as of today" figures change at Lagos midnight.
 *
 * On the report world (report-world.php), the clock at 26 Jan 2027 10:00
 * (frozen: nothing here waits out the five minutes). This month (1–26 Jan
 * 2027) for Adeyemi: ₦350,000 billed (February's rents, the levy voided),
 * ₦3,595,000 collected, ₦100,000 spent.
 */

/** The tables the reports work their figures out from (not `properties`: the page's building picker reads it). */
const REPORT_SOURCE_TABLES = ['bills', 'payments', 'payment_allocations', 'receipts', 'tenant_credit_entries', 'expenses', 'units', 'leases', 'tenant_profiles'];

beforeEach(function () {
    Notification::fake();
    statementWorld();
});

afterEach(fn () => exportsFreshRequest());

/** The queries $load ran against the reports' source tables. */
function reportSourceQueries(Closure $load): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $load();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $tables = implode('|', REPORT_SOURCE_TABLES);

    return array_values(array_filter(
        array_map(static fn (array $query): string => (string) $query['query'], $log),
        static fn (string $sql): bool => preg_match('/\b(from|join)\s+[`"]?('.$tables.')[`"]?(\s|$)/i', $sql) === 1,
    ));
}

/** The report as the member sees it, in a fresh request (so nothing is kept in memory between two calls). */
function freshReportOf(Organization $organization, User $user, array $filters = []): PortfolioReport
{
    exportsFreshRequest();

    return reportOf($organization, $user, $filters);
}

/** [billed, collected] in kobo of this month's report. */
function billedAndCollected(PortfolioReport $report): array
{
    return [$report->collections->totals->billedTotal()->kobo, $report->collections->totals->collectedTotal()->kobo];
}

it('acceptance: a second load of the Reports page reads its figures from the cache and runs none of the reports\' queries', function () {
    // The first load works the figures out from the records.
    expect(reportSourceQueries(fn () => reportsPage($this->owner)->assertOk()
        ->assertSee('data-test="summary-billed">₦350,000<', false)
        ->assertSee('data-test="summary-collected">₦3,595,000<', false)))->not->toBe([]);

    // The second reads them back: the same figures, not one query on bills, payments, expenses, units or leases.
    expect(reportSourceQueries(fn () => reportsPage($this->owner)->assertOk()
        ->assertSee('data-test="summary-billed">₦350,000<', false)
        ->assertSee('data-test="summary-collected">₦3,595,000<', false)
        ->assertSee('Bisi Adeyinka')))->toBe([]);

    // The same entry serves the report builder (and so the PDF, which prints it).
    expect(reportSourceQueries(function () {
        expect(billedAndCollected(freshReportOf($this->acme, $this->owner)))->toBe([35_000_000, 359_500_000]);
    }))->toBe([]);
    expect(reportSourceQueries(fn () => reportsPdf($this->owner)->assertOk()->assertHeader('Content-Type', 'application/pdf')))->toBe([]);

    // Each export: worked out once, read back the next time, the page's figures.
    $first = null;
    expect(reportSourceQueries(function () use (&$first) {
        $first = reportRows($this->owner, 'report_collections');
    }))->not->toBe([]);
    expect(reportSourceQueries(function () use ($first) {
        expect(reportRows($this->owner, 'report_collections'))->toBe($first);
    }))->toBe([])
        ->and($first[1])->toContain('Jan 2027', '350000.00', '3595000.00');
});

it('acceptance: a bill issued shows on the next load', function () {
    // March's bills are issued on 22 Feb (7 days ahead); the clock stays on that instant.
    $this->travelTo('2027-02-22 00:10:00');
    $filters = ['from' => '2027-02-01', 'to' => '2027-02-22'];
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner, $filters)))->toBe([0, 0]);
    expect(reportSourceQueries(fn () => freshReportOf($this->acme, $this->owner, $filters)))->toBe([]);

    runBillGeneration();

    // Bisi's ₦200,000 rent and ₦25,000 levy, Chuka's ₦150,000 rent.
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner, $filters)))->toBe([37_500_000, 0]);
    reportsPage($this->owner, 'from=2027-02-01&to=2027-02-22')->assertOk()->assertSee('data-test="summary-billed">₦375,000<', false);
});

it('acceptance: a bill voided shows on the next load', function () {
    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-billed">₦350,000<', false);
    $warm = freshReportOf($this->acme, $this->owner);
    expect($warm->arrears->notYetDue->kobo)->toBe(33_000_000);

    // Bisi's February rent (₦200,000, nothing paid) is voided.
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, rentBillFor($this->acme, $this->bisiLease, '2027-02-01')), 'Raised in error'));

    $report = freshReportOf($this->acme, $this->owner);
    expect(billedAndCollected($report))->toBe([15_000_000, 359_500_000])
        ->and($report->arrears->notYetDue->kobo)->toBe(13_000_000);
    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-billed">₦150,000<', false);
});

it('acceptance: a payment confirmed shows on the next load, on the page, in the export and in the PDF\'s report alike', function () {
    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-collected">₦3,595,000<', false);
    expect(reportRows($this->owner, 'report_collections')[1])->toContain('3595000.00')
        ->and(freshReportOf($this->acme, $this->owner)->arrears->total()->kobo)->toBe(10_000_000);

    // Bisi pays the ₦100,000 still owed on her January rent.
    confirmedPayment($this->acme, $this->owner, $this->bisi, '100,000', [], [$this->bisiJanRent->id => '100,000']);

    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-collected">₦3,695,000<', false);
    expect(reportRows($this->owner, 'report_collections')[1])->toContain('3695000.00');
    $report = freshReportOf($this->acme, $this->owner);
    expect(billedAndCollected($report))->toBe([35_000_000, 369_500_000])
        ->and($report->arrears->total()->kobo)->toBe(0);
});

it('acceptance: a payment voided (its receipt) and credit refunded are relevant writes', function () {
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner)))->toBe([35_000_000, 359_500_000]);

    // Chuka's receipt is voided: his ₦150,000 counts negative in January.
    voidReceiptAlone($this->acme, $this->owner, receiptOf($this->acme, $this->chukaPayment));
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner)))->toBe([35_000_000, 344_500_000]);

    // Kemi (nothing owed) pays ₦20,000 ahead: credit, not collected until it is used.
    confirmedPayment($this->acme, $this->owner, $this->kemi, '20,000');
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner)))->toBe([35_000_000, 344_500_000]);
    expect(reportSourceQueries(fn () => freshReportOf($this->acme, $this->owner)))->toBe([]);

    // Her credit refunded is never collected either, but the refund is still a write:
    // the next load works the figures out again (and they stay the same).
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(TenantCredit::class)->closeForHandover($this->kemi->id, Money::naira(20_000), true, $this->owner, 'Refunded to the tenant by transfer')));

    expect(reportSourceQueries(function () {
        expect(billedAndCollected(freshReportOf($this->acme, $this->owner)))->toBe([35_000_000, 344_500_000]);
    }))->not->toBe([]);
});

it('acceptance: an expense added shows on the next load', function () {
    expect(freshReportOf($this->acme, $this->owner)->expenses->total()->kobo)->toBe(10_000_000);

    recordExpense($this->acme, $this->owner, ['propertyId' => (string) $this->palm->id, 'description' => 'Gate motor repaired', 'amount' => '25,000', 'spentOn' => '2027-01-26']);

    $report = freshReportOf($this->acme, $this->owner);
    expect($report->expenses->total()->kobo)->toBe(12_500_000)
        ->and(collect($report->buildings->lines)->firstWhere('name', 'Palm Court')?->expenses->kobo)->toBe(8_500_000);
});

it('acceptance: a unit change and a lease change show on the next load', function () {
    // Flat 4C at Palm Court stands vacant.
    $occupancy = freshReportOf($this->acme, $this->owner)->occupancy->totals;
    expect([$occupancy->vacant, $occupancy->underMaintenance])->toBe([1, 0]);

    inOrganization($this->acme, fn () => app(Units::class)->setStatus($this->flat4c->id, UnitStatus::UnderMaintenance, 'Repainting'));

    $occupancy = freshReportOf($this->acme, $this->owner)->occupancy->totals;
    expect([$occupancy->vacant, $occupancy->underMaintenance])->toBe([0, 1]);

    // A lease at Office Block ending on 31 Mar 2027 (64 days ahead), paid up front: not renewed yet, then a renewal drafted.
    $ending = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->office, ['unit_label' => 'Suite A']), tenantIn($this->acme, ['first_name' => 'Ife', 'last_name' => 'Ola', 'phone' => '+2348031230009']), ['startDate' => '2026-04-01', 'endDate' => '2027-03-31', 'paidUntil' => '2027-03-31', 'rent' => '1,200,000', 'deposit' => '0']);
    expect(collect(freshReportOf($this->acme, $this->owner)->renewals->lines)->firstWhere('leaseId', $ending->id)?->renewalState)->toBe('not_renewed');

    inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, storedLease($this->acme, $ending), leaseInput(['startDate' => '2027-04-01', 'endDate' => '2028-03-31', 'deposit' => '0'])));

    expect(collect(freshReportOf($this->acme, $this->owner)->renewals->lines)->firstWhere('leaseId', $ending->id)?->renewalState)->toBe('renewal_drafted');
});

it('never serves one organization\'s figures to another, and a write in one leaves the other\'s cache alone', function () {
    $filters = ['from' => '2026-12-01', 'to' => '2027-01-26'];
    expect(billedAndCollected(freshReportOf($this->acme, $this->owner, $filters)))->toBe([402_500_000, 359_500_000]);

    // Bello Homes, the same filters: its own figures, worked out from its records.
    expect(reportSourceQueries(function () use ($filters) {
        expect(billedAndCollected(freshReportOf($this->other, $this->otherOwner, $filters)))->toBe([330_000_000, 90_000_000]);
    }))->not->toBe([]);

    // A payment at Bello: Bello's figures change, Adeyemi's are still read from the cache.
    confirmedPayment($this->other, $this->otherOwner, $this->harbourLease->tenant_profile_id, '2,400,000', [], [$this->harbourBill->id => '2,400,000']);
    expect(reportSourceQueries(function () use ($filters) {
        expect(billedAndCollected(freshReportOf($this->acme, $this->owner, $filters)))->toBe([402_500_000, 359_500_000]);
    }))->toBe([])
        ->and(billedAndCollected(freshReportOf($this->other, $this->otherOwner, $filters)))->toBe([330_000_000, 330_000_000]);

    // The page too: Bello's owner never sees Adeyemi's buildings or tenants.
    reportsPage($this->otherOwner, 'from=2026-12-01&to=2027-01-26', 'other-realty')->assertOk()
        ->assertSee('data-test="summary-collected">₦3,300,000<', false)
        ->assertDontSee('Marina Heights')->assertDontSee('Bisi Adeyinka');
});

it('keeps a member narrowed to buildings and a member who sees every building apart', function () {
    $agent = joinAs(User::factory()->create(['name' => 'Chidi Agent']), $this->acme, OrganizationRole::Agent);
    assignIn($this->acme, $agent, $this->palm);
    inOrganization($this->acme, fn () => $agent->givePermissionTo('reports.view'));

    // The owner's page first (every building, the organization's own money)…
    reportsPage($this->owner)->assertOk()->assertSee('Marina Heights')->assertSee('data-test="summary-collected">₦3,595,000<', false);

    // …then the agent's: worked out for Palm Court only, never the owner's entry.
    expect(reportSourceQueries(fn () => reportsPage($agent)->assertOk()
        ->assertSee('data-test="summary-collected">₦1,500,000<', false)
        ->assertDontSee('Marina Heights')->assertDontSee('Bisi Adeyinka')))->not->toBe([]);
    $narrowed = freshReportOf($this->acme, $agent);
    expect($narrowed->scope->propertyIds)->toBe([$this->palm->id])
        ->and(array_map(fn ($line) => $line->name, $narrowed->buildings->lines))->toBe(['Palm Court'])
        ->and($narrowed->expenses->includesOrganization)->toBeFalse();

    // And the owner still reads every building, from the cache.
    expect(reportSourceQueries(fn () => reportsPage($this->owner)->assertOk()->assertSee('Marina Heights')))->toBe([]);
});

it('works the "as of today" figures out again at Lagos midnight, with no write in between', function () {
    // 31 Jan, 23:58 in Lagos: February's bills (due 1 Feb) are not yet due; Bisi's January rent is 30 days past due.
    $this->travelTo(CarbonImmutable::parse('2027-01-31 23:58:00', 'Africa/Lagos'));
    $before = freshReportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-31'])->arrears;
    expect($before->notYetDue->kobo)->toBe(33_000_000)
        ->and(array_map(fn ($line) => [$line->bill->tenantName, $line->days, $line->bucket->value], $before->lines))->toBe([['Bisi Adeyinka', 30, '0_30']]);
    $generation = inOrganization($this->acme, fn () => app(DashboardGenerations::class)->current($this->acme->id)->token);

    // Three minutes later it is 1 Feb in Lagos: the cached entry (still fresh) is not today's.
    $this->travelTo(CarbonImmutable::parse('2027-02-01 00:01:00', 'Africa/Lagos'));
    $after = freshReportOf($this->acme, $this->owner, ['from' => '2027-01-01', 'to' => '2027-01-31'])->arrears;
    expect($after->asOf->toDateString())->toBe('2027-02-01')
        ->and($after->notYetDue->kobo)->toBe(0)
        ->and(array_map(fn ($line) => [$line->bill->tenantName, $line->days, $line->bucket->value], $after->lines))
        ->toBe([['Bisi Adeyinka', 31, '31_60'], ['Bisi Adeyinka', 0, '0_30'], ['Chuka Obi', 0, '0_30']])
        ->and(app(DashboardGenerations::class)->current($this->acme->id)->token)->toBe($generation);

    // The renewals' window moves with the day too.
    expect(freshReportOf($this->acme, $this->owner)->renewals->from->toDateString())->toBe('2027-02-01');
});

it('serves GoProperty staff in a read-only support session the cached figures, the same as the owner\'s', function () {
    reportsPage($this->owner)->assertOk()->assertSee('data-test="summary-collected">₦3,595,000<', false);

    seedStaffRoles();
    $handOff = startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::ReadOnly);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    expect(reportSourceQueries(fn () => asSupport()->get('/o/acme-homes/reports')->assertOk()
        ->assertSee('data-test="summary-collected">₦3,595,000<', false)))->toBe([]);
});

it('caches each part apart from the whole page, under the same generation, so they agree', function () {
    $filters = ReportFilters::fromInput([]);
    $page = freshReportOf($this->acme, $this->owner);

    exportsFreshRequest();
    $parts = inOrganization($this->acme, function () use ($filters) {
        $reports = app(PortfolioReports::class);
        $scope = $reports->scope($this->owner, $filters);

        return [$reports->collections($scope, $filters), $reports->arrears($scope, $filters), $reports->buildings($scope, $filters)];
    });

    expect($parts[0])->toEqual($page->collections)
        ->and($parts[1])->toEqual($page->arrears)
        ->and($parts[2])->toEqual($page->buildings);
});
