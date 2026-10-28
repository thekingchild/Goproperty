<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Models\Bill;
use Modules\Import\Enums\ImportStatus;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRecorded;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\PaymentNotice;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P03-13b: the Payment history import sheet (DATA_IMPORT.md § 1 row 8):
 * payments from before GoProperty, for the records and statements only.
 * Confirmed payments of source `import` that settle no bill and add no
 * credit; no receipt unless the row asks, and the tenant is never told.
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

/** The standard history rows: Adaeze's yearly rent paid last December, Bayo's two monthly rents, the last one with a receipt asked for. */
function standardHistoryRows(): array
{
    return [
        historyRow(['Your ID' => 'P-1', 'Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '₦1,500,000', 'Method' => 'Bank transfer', 'Reference' => 'TRF-2025-1215']),
        historyRow(['Tenant' => '0803 555 0102', 'Date paid' => '14/09/2026', 'Amount' => '125,000', 'Method' => 'Cash', 'Note' => 'Paid at the office']),
        historyRow(['Tenant' => '0803 555 0102', 'Date paid' => '14/10/2026', 'Amount' => '125,000', 'Method' => 'Transfer', 'Reference' => 'GTB-1410', 'Issue receipt' => 'Yes']),
    ];
}

it('acceptance: payment history issues no receipts and tells nobody by default; a receipt only where the row asks, still unsent', function () {
    Notification::fake();
    Mail::fake();
    $confirmed = [];
    Event::listen(PaymentConfirmed::class, function (PaymentConfirmed $event) use (&$confirmed): void {
        $confirmed[] = [$event->source, $event->issueReceipt, $event->allocations, $event->creditKobo];
    });
    Event::fake([PaymentRecorded::class]);

    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(
        balances: [balanceRow(['Tenant' => '0803 555 0102', 'Building' => 'Palm Court', 'Unit' => 'Flat 2', 'Amount owed' => '250,000', 'As-of date' => '27/10/2026'])],
        history: standardHistoryRows(),
    ));

    expect($batch->status)->toBe(ImportStatus::DryRunPassed)
        ->and(portfolioCounts($batch, 'payment_history'))->toMatchArray(['create' => 3, 'update' => 0, 'restore' => 0, 'skip' => 0])
        ->and(sheetIssues($batch, 'payment_history'))->toBe([
            [4, 'Issue receipt', 'H', 'warning', 'A receipt is issued for this payment, numbered today. The tenant is not sent it.'],
        ]);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    $adaeze = inOrganization($this->acme, fn () => TenantProfile::query()->where('phone', '+2348035550101')->sole());
    $bayo = inOrganization($this->acme, fn () => TenantProfile::query()->where('phone', '+2348035550102')->sole());
    [$december, $september, $october] = paymentsOf($this->acme)->sortBy('id')->values()->all();

    // Confirmed history of source `import`, the batch's: no allocation, no credit.
    expect($batch->result_counts['targets']['payment_history'])->toMatchArray(['create' => 3, 'skip' => 0])
        ->and([$december->tenant_profile_id, $december->amount_kobo->kobo, $december->method, $december->paid_at->toDateString(), $december->reference, $december->external_ref])
        ->toBe([$adaeze->id, 150_000_000, PaymentMethod::BankTransfer, '2025-12-15', 'TRF-2025-1215', 'P-1'])
        ->and([$september->tenant_profile_id, $september->method, $september->note])->toBe([$bayo->id, PaymentMethod::Cash, 'Paid at the office'])
        ->and($october->method)->toBe(PaymentMethod::BankTransfer)
        ->and(collect([$december, $september, $october])->every(fn ($payment) => $payment->status === PaymentStatus::Confirmed
            && $payment->source === PaymentSource::Import
            && $payment->import_batch_id === $batch->id
            && $payment->confirmed_by_user_id === null
            && $payment->recorded_by_user_id === null
            && $payment->bill_id === null))->toBeTrue()
        ->and(inOrganization($this->acme, fn () => PaymentAllocation::query()->count()))->toBe(0)
        ->and(creditLedgerOf($this->acme))->toBe([])
        ->and($confirmed)->toBe([['import', false, [], 0], ['import', false, [], 0], ['import', true, [], 0]]);

    // No receipt by default; one for the row that asked, numbered now, saying where it came from and that it was not sent.
    $receipt = receiptsOf($this->acme)->sole();
    expect($december->receipt_id)->toBeNull()
        ->and($september->receipt_id)->toBeNull()
        ->and($october->receipt_id)->toBe($receipt->id)
        ->and($receipt->payment_id)->toBe($october->id)
        ->and($receipt->number)->toBe('RCT-ADR-2026-000001')
        ->and($receipt->details['confirmed_by'])->toBe('Imported from earlier records')
        ->and($receipt->details['sent_via'])->toBe([])
        ->and($receipt->details['allocations'])->toBe([]);

    // Nobody is told: no notice row, no notification, no mail, no WhatsApp.
    Event::assertNotDispatched(PaymentRecorded::class);
    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    expect(inOrganization($this->acme, fn () => PaymentNotice::query()->count()))->toBe(0)
        ->and(leaseImportWhatsApp())->toHaveCount(0);

    // The history settles nothing: Bayo's opening balance still asks for its ₦250,000.
    $arrears = inOrganization($this->acme, fn () => Bill::query()->where('type', BillType::OpeningBalance)->sole());
    expect($arrears->paid_kobo->kobo)->toBe(0)->and($arrears->status)->toBe(BillStatus::Overdue);

    // On the payments list as imported history, with nothing to confirm.
    paymentsPage($this->owner, '?status=confirmed')->assertOk()->assertSee('Imported history');
    expect(listedPayments(paymentsPage($this->owner, '?status=pending_confirmation')))->toBe([]);
});

it('imports the same history again without adding it twice, and issues a receipt asked for later', function () {
    portfolioImport($this->acme, $this->owner, balancesWorkbook(history: standardHistoryRows()));

    $rows = standardHistoryRows();
    $rows[1][7] = 'Yes'; // September's cash payment now asks for its receipt
    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(history: $rows));

    expect(portfolioCounts($batch, 'payment_history'))->toMatchArray(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and(sheetIssues($batch, 'payment_history'))->toBe([
            [2, null, null, 'warning', 'This payment is on GoProperty already: nothing changes.'],
            [3, null, null, 'warning', 'This payment is on GoProperty already: nothing changes.'],
            [3, 'Issue receipt', 'H', 'warning', 'A receipt is issued for this payment, numbered today. The tenant is not sent it.'],
            [4, null, null, 'warning', 'This payment is on GoProperty already: nothing changes.'],
        ]);

    $batch = portfolioCommit($this->acme, $this->owner, $batch);
    $payments = paymentsOf($this->acme)->sortBy('id')->values();

    expect($batch->result_counts['targets']['payment_history'])->toMatchArray(['update' => 3, 'create' => 0])
        ->and($payments)->toHaveCount(3)
        ->and(receiptsOf($this->acme)->pluck('payment_id')->all())->toBe([$payments[2]->id, $payments[1]->id])
        ->and(receiptsOf($this->acme)->pluck('number')->all())->toBe(['RCT-ADR-2026-000001', 'RCT-ADR-2026-000002']);
});

it('reports every bad cell of the Payment history sheet with its exact row, column and message', function () {
    $batch = portfolioDryRun($this->acme, $this->owner, balancesWorkbook(history: [
        historyRow(['Tenant' => '0809 000 0000', 'Date paid' => '15/12/2025', 'Amount' => '1,000', 'Method' => 'Cash']),            // 2
        historyRow(['Tenant' => 'T-1', 'Amount' => '1,000', 'Method' => 'Cash']),                                                   // 3
        historyRow(['Tenant' => 'T-1', 'Date paid' => '29/10/2026', 'Amount' => '1,000', 'Method' => 'Cash']),                      // 4
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Method' => 'Cash']),                                           // 5
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '-1,000', 'Method' => 'Cash']),                    // 6
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '1,000', 'Method' => 'Cheque']),                    // 7
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '1,000']),                                         // 8
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '1,000', 'Method' => 'POS', 'Issue receipt' => 'Maybe']), // 9
        historyRow(['Tenant' => 'T-1', 'Date paid' => '15/12/2025', 'Amount' => '1,000', 'Method' => 'POS', 'Reference' => '<script>']), // 10
        historyRow(['Your ID' => 'P-1', 'Tenant' => 'T-2', 'Date paid' => '15/12/2025', 'Amount' => '2,000', 'Method' => 'Cash']),  // 11: fine
        historyRow(['Your ID' => 'P-1', 'Tenant' => 'T-2', 'Date paid' => '16/12/2025', 'Amount' => '2,000', 'Method' => 'Cash']),  // 12
        historyRow(['Tenant' => 'T-3', 'Date paid' => '15/12/2025', 'Amount' => '3,000', 'Method' => 'Cash']),                     // 13: fine
        historyRow(['Tenant' => '0803 555 0103', 'Date paid' => '15/12/2025', 'Amount' => '3,000', 'Method' => 'cash']),           // 14
    ]));

    expect($batch->status)->toBe(ImportStatus::DryRunFailed)
        ->and(sheetIssues($batch, 'payment_history'))->toBe([
            [2, 'Tenant', 'B', 'error', 'No tenant has the phone number or ID "0809 000 0000", here or in the Tenants sheet. Add them to the Tenants sheet first.'],
            [3, 'Date paid', 'C', 'error', 'Enter the day the tenant paid, as DD/MM/YYYY.'],
            [4, 'Date paid', 'C', 'error', 'A payment in the history was made today or earlier.'],
            [5, 'Amount', 'D', 'error', 'Enter the amount the tenant paid.'],
            [6, 'Amount', 'D', 'error', 'Enter an amount above ₦0.'],
            [7, 'Method', 'E', 'error', 'Choose one of: Bank transfer, Cash, POS, Paystack.'],
            [8, 'Method', 'E', 'error', 'Choose one of: Bank transfer, Cash, POS, Paystack.'],
            [9, 'Issue receipt', 'H', 'error', 'Enter Yes or No.'],
            [10, 'Reference', 'F', 'error', 'Use plain text only (no < or >).'],
            [12, 'Your ID', 'A', 'error', 'This ID is already on row 11. Each payment goes on one row.'],
            [14, null, null, 'error', 'Row 13 has the same payment (tenant, date, amount, method and reference). Give each its own Your ID or reference, or leave the copy out.'],
        ])
        ->and(paymentsOf($this->acme))->toHaveCount(0);
});
