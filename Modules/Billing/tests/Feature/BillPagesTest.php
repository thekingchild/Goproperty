<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Models\Bill;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-04b: the bill pages (list with search, filters and export; the
 * bill's own page), and the lease page's link to its bills.
 */

beforeEach(function () {
    // Bills issue their invoice PDF on the pdf queue (P03-05c); not this test's business.
    holdInvoicePdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
});

afterEach(fn () => billsFreshRequest());

/** A tenant with fixed contact details, so searches match only what they should. */
function billTenant(Organization $organization, int $n, string $first, string $last): TenantProfile
{
    return tenantIn($organization, ['first_name' => $first, 'last_name' => $last, 'phone' => sprintf('+234803%07d', 1000 + $n), 'email' => "person{$n}@example.test"]);
}

it('lists bills 25 a page, latest due first, with a debounced search and filters kept in the URL', function () {
    $tenants = [];
    $leases = [];

    foreach (range(1, 27) as $n) {
        $tenants[$n] = billTenant($this->acme, $n, "Tenant{$n}", 'Okafor');
        $leases[$n] = activeLease($this->acme, $this->owner, unitIn($this->acme, $n % 2 === 0 ? $this->oak : $this->palm, ['unit_label' => "Unit {$n}"]), $tenants[$n], ['startDate' => '2027-01-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'endDate' => '2027-12-31']);
    }

    runBillGeneration();
    $this->travelTo('2027-01-21 09:00:00');
    runBillGeneration();
    $bills = billsOf($this->acme);
    $all = $bills->sortByDesc(fn (Bill $bill) => $bill->due_date->toDateString().str_pad((string) $bill->id, 9, '0', STR_PAD_LEFT))->pluck('id')->values()->all();
    $twelve = $bills->firstWhere('lease_id', $leases[12]->id);
    $void = $bills->firstWhere('lease_id', $leases[3]->id);
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $void, 'Entered twice'));

    expect($bills)->toHaveCount(27)
        ->and(listedBills(billsPage($this->owner)))->toBe(array_slice($all, 0, 25))
        ->and(listedBills(billsPage($this->owner, '?page=2')))->toBe(array_slice($all, 25))
        ->and(listedBills(billsPage($this->owner, '?status=void')))->toBe([$void->id])
        // Bills made on 21 Jan for periods already started were overdue at once.
        ->and(listedBills(billsPage($this->owner, '?status=overdue')))->toEqualCanonicalizing(billsOf($this->acme)->where('status.value', 'overdue')->pluck('id')->all())
        ->and(billsOf($this->acme)->where('status.value', 'overdue')->count())->toBe(18)
        ->and(listedBills(billsPage($this->owner, '?status=unpaid')))->toHaveCount(8)
        ->and(listedBills(billsPage($this->owner, '?type=rent')))->toHaveCount(25)
        ->and(listedBills(billsPage($this->owner, '?type=utility')))->toBe([])
        ->and(listedBills(billsPage($this->owner, '?building='.$this->oak->id)))->toHaveCount(13)
        ->and(listedBills(billsPage($this->owner, '?lease='.$leases[12]->id)))->toBe([$twelve->id])
        ->and(listedBills(billsPage($this->owner, '?tenant='.$tenants[12]->id)))->toBe([$twelve->id])
        ->and(listedBills(billsPage($this->owner, '?q=Tenant12')))->toBe([$twelve->id])
        ->and(listedBills(billsPage($this->owner, '?q='.urlencode($twelve->number))))->toBe([$twelve->id])
        ->and(listedBills(billsPage($this->owner, '?q='.urlencode(strtolower(str_replace('-', ' ', $twelve->payment_reference))))))->toBe([$twelve->id])
        ->and(listedBills(billsPage($this->owner, '?status=bogus')))->toHaveCount(25);

    billsPage($this->owner, '?lease='.$leases[12]->id)->assertSee('data-test="show-all-bills"', false);

    // Typed on the page: back to page one, filters cleared in one go.
    $searched = billsUpdate($this->owner, billsSnapshot($this->owner, '?page=2'), ['search' => 'Tenant3'])->assertOk();
    expect(listedBills($searched))->toBe([$void->id]);
    $cleared = billsUpdate($this->owner, LivewireHttp::next($searched), [], [['clearFilters']])->assertOk();
    expect(listedBills($cleared))->toBe(array_slice($all, 0, 25));
});

it('loads a page of bills without a query per bill (no N+1)', function () {
    $count = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += preg_match('/\b(bills|units|properties|tenant_profiles)\b/', $query->sql) === 1 ? 1 : 0;
        });
        billsPage($this->owner)->assertOk();

        return $queries;
    };

    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), billTenant($this->acme, 1, 'Kemi', 'Adebayo'));
    runBillGeneration();
    $one = $count();
    expect($one)->toBeGreaterThan(3);

    foreach (range(2, 21) as $n) {
        activeLease($this->acme, $this->owner, unitIn($this->acme, $n % 2 === 0 ? $this->oak : $this->palm), billTenant($this->acme, $n, "Tenant{$n}", 'Okafor'));
    }

    runBillGeneration();
    expect(billsOf($this->acme))->toHaveCount(21)
        ->and($count())->toBe($one);
});

it('shows empty states', function () {
    billsPage($this->owner)->assertOk()->assertSee('No bills yet');
    billsPage($this->owner, '?q=nobody')->assertOk()->assertSee('No bill matches')->assertSee('data-test="clear-filters"', false);
});

it('shows a bill with its lines, money and payment reference, and links lease and bills both ways', function () {
    $lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), billTenant($this->acme, 1, 'Kemi', 'Adebayo'), ['dueDayOffset' => '5']);
    runBillGeneration();
    $bill = billsOf($this->acme)->sole();
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($bill->id, Money::naira(500_000))));

    billsPage($this->owner, "/{$bill->id}")->assertOk()
        ->assertSee('INV-ADR-2026-000001')
        ->assertSee('Kemi Adebayo')
        ->assertSee('Flat 1 · Palm Court')
        ->assertSeeInOrder(['data-test="bill-status"', 'Partly paid'], false)
        ->assertSeeInOrder(['data-test="bill-period"', '01/01/2027 to 31/12/2027'], false)
        ->assertSeeInOrder(['data-test="bill-due"', '06/01/2027'], false)
        ->assertSeeInOrder(['data-test="payment-reference"', $bill->payment_reference], false)
        ->assertSeeInOrder(['data-test="bill-amount"', '₦1,500,000'], false)
        ->assertSeeInOrder(['data-test="bill-paid"', '₦500,000'], false)
        ->assertSeeInOrder(['data-test="bill-outstanding"', '₦1,000,000'], false)
        ->assertSee('Yearly rent, lease LS-ADR-2026-000001')
        ->assertSee('href="'.route('workspace.leases.show', [$this->acme, $lease->id]).'"', false);

    leasesPage($this->owner, "/{$lease->id}")->assertOk()
        ->assertSee('href="'.route('workspace.bills.index', [$this->acme, 'lease' => $lease->id]).'"', false);
    leasesPage($this->owner, '/'.draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), billTenant($this->acme, 2, 'Bola', 'Ade'))->id)
        ->assertOk()->assertDontSee('data-test="lease-bills"', false);
});

it('exports the bills the page shows, in Excel and CSV, with its filters, audited', function () {
    $kemi = billTenant($this->acme, 1, 'Kemi', 'Adebayo');
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $kemi);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']), billTenant($this->acme, 2, 'Bola', 'Ade'), ['billingCycle' => 'monthly', 'rent' => '150,000', 'dueDayOffset' => '2']);
    runBillGeneration();
    [$yearly, $monthly] = billsOf($this->acme)->all();
    inOrganization($this->acme, fn () => DB::transaction(fn () => app(BillLedger::class)->apply($yearly->id, Money::naira(250_000))));
    $this->travelTo('2026-12-26 08:00:00');
    inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, $monthly, 'Wrong rent'));

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/bills', 'bills', $format);

        expect($rows[0])->toBe(['Bill number', 'Payment reference', 'Type', 'Status', 'Tenant', 'Building', 'Unit', 'Description', 'Period start', 'Period end', 'Due date', 'Amount', 'Paid', 'Outstanding', 'Issued on', 'Voided on', 'Void reason'])
            ->and(count($rows))->toBe(3)
            // Latest due first: the monthly bill (due 3 Jan), then the yearly one (due 1 Jan).
            ->and($rows[1])->toBe([$monthly->number, $monthly->payment_reference, 'Rent', 'Void', 'Bola Ade', 'Oak Lodge', 'Oak 1', 'Rent, 01/01/2027 to 31/01/2027', '2027-01-01', '2027-01-31', '2027-01-03', '150000.00', '0.00', '0.00', '2026-12-25 10:00', '2026-12-26 09:00', 'Wrong rent'])
            ->and($rows[2])->toBe([$yearly->number, $yearly->payment_reference, 'Rent', 'Partly paid', 'Kemi Adebayo', 'Palm Court', 'Flat 1', 'Rent, 01/01/2027 to 31/12/2027', '2027-01-01', '2027-12-31', '2027-01-01', '1500000.00', '250000.00', '1250000.00', '2026-12-25 10:00', '', '']);
    }

    // The page's filters, typed or from the URL.
    expect(exportColumn(exportRows($this->owner, '/bills?status=void', 'bills'), 'Bill number'))->toBe([$monthly->number])
        ->and(exportColumn(exportRows($this->owner, '/bills', 'bills', 'csv', ['tenant' => (string) $kemi->id]), 'Bill number'))->toBe([$yearly->number]);

    $audit = AuditEntry::query()->where('log_name', 'exports')->latest('id')->first();
    expect($audit->properties['list'])->toBe('bills')
        ->and($audit->causer_id)->toBe($this->owner->id);
});
