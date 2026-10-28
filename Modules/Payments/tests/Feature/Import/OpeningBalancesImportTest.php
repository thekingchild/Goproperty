<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Events\BillOverdue;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Import\Enums\ImportStatus;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P03-13b: the Opening balances import sheet (DATA_IMPORT.md § 1 row 6,
 * § 3) through the real engine. What a tenant owed on the as-of date
 * becomes ONE `opening_balance` bill, due on that date: payable at once and
 * counted in arrears, never back-billed per period. A credit becomes a
 * tenant credit entry the next bill takes. Nobody is contacted.
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

/** The imported tenant with this phone. */
function balanceTenant(Organization $organization, string $phone): TenantProfile
{
    return inOrganization($organization, fn (): TenantProfile => TenantProfile::query()->where('phone', $phone)->sole());
}

/** The organization's opening balance bills, oldest first. */
function openingBalanceBills(Organization $organization): array
{
    return inOrganization($organization, fn (): array => Bill::query()->where('type', BillType::OpeningBalance)->orderBy('id')->get()->all());
}

it('acceptance: an opening balance is payable and counts as arrears: one bill, due on the as-of date, never billed per period', function () {
    Notification::fake();
    Mail::fake();
    Event::fake([BillOverdue::class]);
    $issued = [];
    Event::listen(BillIssued::class, function (BillIssued $event) use (&$issued): void {
        $issued[] = [$event->type, $event->imported];
    });

    $file = balancesWorkbook([
        balanceRow(['Your ID' => 'OB-1', 'Tenant' => '0803 555 0102', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Amount owed' => '₦300,000', 'As-of date' => '30/09/2026', 'Description' => 'Rent arrears, August and September']),
        balanceRow(['Your ID' => 'OB-2', 'Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '45,000.50', 'As-of date' => '28/10/2026']),
    ]);
    $batch = portfolioDryRun($this->acme, $this->owner, $file);

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(portfolioCounts($batch, 'opening_balances'))->toMatchArray(['create' => 2, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(sheetIssues($batch, 'opening_balances'))->toBe([])
        ->and(openingBalanceBills($this->acme))->toBe([]);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    $bayo = balanceTenant($this->acme, '+2348035550102');
    $adaeze = balanceTenant($this->acme, '+2348035550101');
    [$yearly, $monthly] = leasesOf($this->acme)->all();
    [$arrears, $today] = openingBalanceBills($this->acme);
    $line = inOrganization($this->acme, fn () => BillItem::query()->where('bill_id', $arrears->id)->sole());

    // One bill each, due on the as-of date: the older one is overdue (arrears) at once.
    expect($batch->result_counts['targets']['opening_balances'])->toMatchArray(['create' => 2, 'skip' => 0])
        ->and($arrears->tenant_profile_id)->toBe($bayo->id)
        ->and($arrears->amount_kobo->kobo)->toBe(30_000_000)
        ->and($arrears->paid_kobo->kobo)->toBe(0)
        ->and($arrears->due_date->toDateString())->toBe('2026-09-30')
        ->and($arrears->period_start)->toBeNull()
        ->and($arrears->period_end)->toBeNull()
        ->and($arrears->status)->toBe(BillStatus::Overdue)
        ->and($arrears->description)->toBe('Opening balance as at 30/09/2026')
        ->and($arrears->billable_type)->toBe(BillableType::Lease)
        ->and($arrears->lease_id)->toBe($monthly->id)
        ->and($arrears->unit_id)->toBe($monthly->unit_id)
        ->and($arrears->property_id)->toBe($monthly->property_id)
        ->and($arrears->import_batch_id)->toBe($batch->id)
        ->and($arrears->number)->toBe('INV-ADR-2026-000001')
        ->and($arrears->details['lease']['number'] ?? null)->toBe($monthly->contract_number)
        ->and($line->label)->toBe('Opening balance')
        ->and($line->note)->toBe('Rent arrears, August and September')
        ->and($line->amount_kobo->kobo)->toBe(30_000_000)
        ->and($today->tenant_profile_id)->toBe($adaeze->id)
        ->and($today->amount_kobo->kobo)->toBe(4_500_050)
        ->and($today->due_date->toDateString())->toBe('2026-10-28')
        ->and($today->status)->toBe(BillStatus::Unpaid)
        ->and($today->lease_id)->toBe($yearly->id)
        ->and($issued)->toBe([['opening_balance', true], ['opening_balance', true]]);

    $balances = openingBalancesOf($this->acme);
    expect($balances->pluck('bill_id')->all())->toBe([$arrears->id, $today->id])
        ->and($balances->pluck('external_ref')->all())->toBe(['OB-1', 'OB-2'])
        ->and($balances->pluck('import_batch_id')->unique()->all())->toBe([$batch->id])
        ->and($balances->map(fn ($balance) => $balance->amount_kobo->kobo)->all())->toBe([30_000_000, 4_500_050]);

    // Counts in arrears: the overdue list shows it, and it is the tenant's oldest open bill.
    expect(listedBills(billsPage($this->owner, '?status=overdue')))->toBe([$arrears->id])
        ->and(array_map(fn ($bill) => $bill->id, inOrganization($this->acme, fn () => app(BillLedger::class)->openBills($bayo->id))))->toBe([$arrears->id]);

    // Payable: its bill page, invoice and the public pay page ask for it.
    billsPage($this->owner, "/{$arrears->id}")->assertOk()->assertSee('Opening balance')->assertSee('₦300,000');
    billsFreshRequest();
    $this->get("/pay/{$arrears->payment_reference}")->assertOk()->assertSee('₦300,000')->assertSee($arrears->number);

    // Never back-billed per period: the leases bill only from their next periods, and no reminder or notice is sent.
    runBillGeneration();
    runOverdueFlagging();
    $this->travelTo('2026-11-08 00:10:00');
    runBillGeneration();
    runOverdueFlagging();

    $rent = inOrganization($this->acme, fn () => Bill::query()->where('type', BillType::Rent)->get());
    expect($rent->map(fn ($bill) => [$bill->lease_id, $bill->period_start->toDateString()])->all())->toBe([[$monthly->id, '2026-11-15']]);
    // Overdue at issue: no "became overdue" event for the arrears; the bill due on the import day is flagged once it passes.
    Event::assertDispatchedTimes(BillOverdue::class, 1);
    Event::assertDispatched(BillOverdue::class, fn (BillOverdue $event): bool => $event->billId === $today->id);
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    expect(leaseImportWhatsApp())->toHaveCount(0);

    // A payment pays the arrears first (oldest due), then the rent; its receipt names the opening balance.
    $payment = confirmedPayment($this->acme, $this->owner, $bayo, '425,000');
    expect(allocationsOf($this->acme, $payment))->toEqual([$arrears->id => 30_000_000, $rent->sole()->id => 12_500_000])
        ->and(storedBill($this->acme, $arrears)->status)->toBe(BillStatus::Paid)
        ->and(receiptOf($this->acme, $payment)->details['allocations'][0]['description'])->toBe('Opening balance');
});

it('acceptance: an imported credit is applied to the next bill', function () {
    $batch = portfolioImport($this->acme, $this->owner, balancesWorkbook([
        balanceRow(['Tenant' => 'T-1', 'Amount owed' => '-100,000', 'As-of date' => '27/10/2026', 'Description' => 'Paid ahead in the old system']),
    ]));
    $adaeze = balanceTenant($this->acme, '+2348035550101');
    [$yearly] = leasesOf($this->acme)->all();
    $balance = openingBalancesOf($this->acme)->sole();

    // A credit entry, not a bill.
    expect($batch->result_counts['targets']['opening_balances'])->toMatchArray(['create' => 1])
        ->and(openingBalanceBills($this->acme))->toBe([])
        ->and($balance->amount_kobo->kobo)->toBe(-10_000_000)
        ->and($balance->bill_id)->toBeNull()
        ->and($balance->unit_id)->toBeNull()
        ->and(creditLedgerOf($this->acme))->toBe([[$adaeze->id, 'opening_balance', 10_000_000]])
        ->and(creditOf($this->acme, $adaeze)->kobo)->toBe(10_000_000);

    // Other tenants' bills never take it.
    $this->travelTo('2026-11-08 00:10:00');
    runBillGeneration();
    expect(creditOf($this->acme, $adaeze)->kobo)->toBe(10_000_000);

    // Adaeze's next bill (the yearly rent from 01/01/2027, issued 25/12/2026) takes it at once.
    $this->travelTo('2026-12-25 00:10:00');
    runBillGeneration();
    $bill = collect(billsOf($this->acme))->firstWhere('lease_id', $yearly->id);

    expect($bill->period_start->toDateString())->toBe('2027-01-01')
        ->and($bill->paid_kobo->kobo)->toBe(10_000_000)
        ->and($bill->status)->toBe(BillStatus::PartiallyPaid)
        ->and(creditOf($this->acme, $adaeze)->kobo)->toBe(0)
        ->and(creditLedgerOf($this->acme))->toBe([[$adaeze->id, 'opening_balance', 10_000_000], [$adaeze->id, 'applied_to_bill', -10_000_000]]);
});

it('reports every bad cell of the Opening balances sheet with its exact row, column and message', function () {
    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook([
        balanceRow(['Tenant' => '0809 000 0000', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026']),        // 2
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'As-of date' => '30/09/2026']),                                             // 3
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '0', 'As-of date' => '30/09/2026']),                        // 4
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => 'lots', 'As-of date' => '30/09/2026']),                     // 5
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000,000,000', 'As-of date' => '30/09/2026']),           // 6
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000', 'As-of date' => '29/10/2026']),                   // 7
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000', 'As-of date' => '31/02/2026']),                   // 8
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000']),                                               // 9
        balanceRow(['Tenant' => 'T-1', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026']),                                                                  // 10
        balanceRow(['Tenant' => 'T-1', 'Unit' => 'Flat 1', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026']),                                              // 11
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 9', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026']),                  // 12
        balanceRow(['Tenant' => 'T-1', 'Building' => 'Palm Court', 'Unit' => 'Flat 1', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026', 'Description' => '<b>x</b>']), // 13
        balanceRow(['Your ID' => 'OB-1', 'Tenant' => 'T-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Amount owed' => '10,000', 'As-of date' => '30/09/2026']), // 14: fine
        balanceRow(['Your ID' => 'OB-1', 'Tenant' => 'T-3', 'Amount owed' => '-5,000', 'As-of date' => '30/09/2026']),                                             // 15
        balanceRow(['Tenant' => '0803 555 0102', 'Building' => 'palm court', 'Unit' => 'flat 2', 'Amount owed' => '20,000', 'As-of date' => '30/09/2026']),        // 16
        balanceRow(['Tenant' => 'T-3', 'Amount owed' => '-5,000', 'As-of date' => '30/09/2026']),                                                                  // 17: fine (a credit needs no unit)
    ]));

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(sheetIssues($batch, 'opening_balances'))->toBe([
            [2, 'Tenant', 'B', 'error', 'No tenant has the phone number or ID "0809 000 0000", here or in the Tenants sheet. Add them to the Tenants sheet first.'],
            [3, 'Amount owed', 'E', 'error', 'Enter what the tenant owed, or a credit as a negative amount such as -50,000.'],
            [4, 'Amount owed', 'E', 'error', 'A balance of ₦0 needs no row: leave it out.'],
            [5, 'Amount owed', 'E', 'error', 'Enter an amount in naira, such as 150,000 or ₦150,000.50.'],
            [6, 'Amount owed', 'E', 'error', 'Enter an amount below ₦10,000,000,000.'],
            [7, 'As-of date', 'F', 'error', 'An opening balance is what was owed on a day that has come: use today or an earlier date.'],
            [8, 'As-of date', 'F', 'error', '"31/02/2026" is not a real date. Enter it as DD/MM/YYYY.'],
            [9, 'As-of date', 'F', 'error', 'Enter the day the balance stood, as DD/MM/YYYY.'],
            [10, 'Building', 'C', 'error', 'An amount owed becomes a bill for the unit: enter its building and unit.'],
            [11, 'Building', 'C', 'error', 'Enter the unit\'s building: its name or Your ID.'],
            [12, 'Unit', 'D', 'error', 'No unit is called "Flat 9" in Palm Court, here or in the Units sheet. Add it to the Units sheet first.'],
            [13, 'Description', 'G', 'error', 'Use plain text only (no < or >).'],
            [15, 'Your ID', 'A', 'error', 'This ID is already on row 14. Each opening balance goes on one row.'],
            [16, 'As-of date', 'F', 'error', '0803 555 0102 already has an opening balance for this unit and date on row 14. Put it on one row.'],
        ])
        ->and(openingBalancesOf($this->acme))->toHaveCount(0);
});

it('keeps each organization\'s opening balances to itself', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty Ltd']);
    $theirOwner = joinAs(User::factory()->create(), $other);
    $rows = [balanceRow(['Your ID' => 'OB-1', 'Tenant' => 'T-2', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Amount owed' => '300,000', 'As-of date' => '30/09/2026'])];

    portfolioImport($other, $theirOwner, balancesWorkbook($rows));

    // The same Your ID, tenant phone and unit names here: nothing of theirs is matched, and our numbers start at 1.
    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook($rows));
    expect(portfolioCounts($batch, 'opening_balances'))->toMatchArray(['create' => 1, 'update' => 0, 'restore' => 0]);
    portfolioCommit($this->acme, $this->owner, $batch);

    $ours = openingBalanceBills($this->acme);
    $theirs = openingBalanceBills($other);
    expect($ours)->toHaveCount(1)
        ->and($theirs)->toHaveCount(1)
        ->and($ours[0]->id)->not->toBe($theirs[0]->id)
        ->and($ours[0]->number)->toBe('INV-ADR-2026-000001')
        ->and(openingBalancesOf($this->acme)->sole()->bill_id)->toBe($ours[0]->id);

    // Their bill is not ours to open.
    billsFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/bills/{$theirs[0]->id}")->assertNotFound();
});
