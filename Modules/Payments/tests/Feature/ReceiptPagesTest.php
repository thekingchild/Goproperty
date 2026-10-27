<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/receipt-helpers.php';

/*
 * P03-10b: the receipt pages (/o/{slug}/receipts and a receipt's page):
 * the list with search, filters (the approvers' "Voids to approve" queue)
 * and export, the receipt's page with its prints, the sidebar item, the
 * payment page's link. Searches use fixed names, phones and emails for
 * every row that must not match.
 */

beforeEach(function () {
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Owner']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->femi = tenantIn($this->acme, ['first_name' => 'Femi', 'last_name' => 'Johnson', 'phone' => '+2348030000002', 'email' => 'femi@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), $this->femi, ['billingCycle' => 'monthly', 'rent' => '80,000', 'deposit' => '0']);
    runBillGeneration();

    $this->first = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000'));
    $this->travelTo('2026-12-25 10:00:00');
    $this->second = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->femi, '80,000'));
    $this->travelTo('2026-12-25 11:00:00');
    $this->third = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemi, '2,500.50'));
});

afterEach(fn () => billsFreshRequest());

it('lists receipts newest first, for owners, administrators and agents, and 404 for everyone else', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);

    foreach ([$this->owner, $admin, $this->agent] as $user) {
        $page = receiptsPage($user)->assertOk()->assertSee('data-nav="receipts"', false)->assertSee('data-test="export-receipts-csv"', false)
            ->assertSee('RCT-')->assertSee('Kemi Adebayo')->assertSee('₦2,500.50');
        expect(listedReceipts($page))->toBe([$this->third->id, $this->second->id, $this->first->id]);
        receiptsPage($user, "/{$this->first->id}")->assertOk()->assertSee($this->first->number)->assertSee('Tunde Owner')->assertSee('data-test="receipt-pdf"', false);
    }

    foreach ([joinAs(User::factory()->create(), $this->acme, OrganizationRole::PropertyOwner), joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant), User::factory()->create()] as $user) {
        receiptsPage($user)->assertNotFound();
        receiptsPage($user, "/{$this->first->id}")->assertNotFound();
        receiptsPage($user, "/{$this->first->id}/pdf")->assertNotFound();
    }
});

it('searches by number and tenant, and filters by status and tenant, all in the URL', function () {
    requestReceiptVoid($this->acme, $this->agent, $this->second, 'Wrong tenant');

    expect(listedReceipts(receiptsPage($this->owner, '?q='.$this->second->number)))->toBe([$this->second->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?q=Kemi')))->toBe([$this->third->id, $this->first->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?q=femi@example.test')))->toBe([$this->second->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?q=Nobody+Here')))->toBe([])
        ->and(listedReceipts(receiptsPage($this->owner, '?status=void_requested')))->toBe([$this->second->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?status=valid')))->toBe([$this->third->id, $this->first->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?tenant='.$this->femi->id)))->toBe([$this->second->id]);

    expect(htmlOf(receiptsPage($this->owner)))->toMatch('/data-test="void-request-count"[^>]*>\s*1\s*</');

    approveReceiptVoid($this->acme, $this->owner, $this->second);
    expect(listedReceipts(receiptsPage($this->owner, '?status=voided')))->toBe([$this->second->id])
        ->and(listedReceipts(receiptsPage($this->owner, '?status=void_requested')))->toBe([]);
});

it('exports the receipts the page shows, in Excel and CSV, with its filters', function () {
    requestReceiptVoid($this->acme, $this->agent, $this->second, '=HYPERLINK("x")');
    approveReceiptVoid($this->acme, $this->owner, $this->second);

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/receipts', 'receipts', $format);
        expect($rows[0])->toBe(['Receipt', 'Issued on', 'Tenant', 'Amount', 'Method', 'Status', 'Void asked by', 'Void approved by', 'Voided on', 'Void reason', 'Single approver'])
            ->and(exportColumn($rows, 'Receipt'))->toBe([$this->third->number, $this->second->number, $this->first->number])
            ->and(exportColumn($rows, 'Tenant'))->toBe(['Kemi Adebayo', 'Femi Johnson', 'Kemi Adebayo'])
            ->and(exportColumn($rows, 'Amount'))->toBe(['2500.50', '80000.00', '100000.00'])
            ->and(exportColumn($rows, 'Status'))->toBe(['Valid', 'Voided', 'Valid'])
            ->and(exportColumn($rows, 'Void asked by'))->toBe(['', 'Bola Agent', ''])
            ->and(exportColumn($rows, 'Void approved by'))->toBe(['', 'Tunde Owner', ''])
            ->and(exportColumn($rows, 'Void reason')[1])->toBe("'=HYPERLINK(\"x\")")
            ->and(exportColumn($rows, 'Single approver'))->toBe(['', 'No', ''])
            ->and(exportColumn($rows, 'Issued on')[0])->toBe('2026-12-25 12:00');
    }

    expect(exportColumn(exportRows($this->owner, '/receipts', 'receipts', 'csv', ['status' => 'voided']), 'Receipt'))->toBe([$this->second->number]);
});

it('pages through receipts without a query per row', function () {
    $queries = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += preg_match('/\b(receipts|tenant_profiles|users)\b/', $query->sql) === 1 ? 1 : 0;
        });
        receiptsPage($this->owner)->assertOk();

        return $queries;
    };

    $before = $queries();
    foreach (range(1, 4) as $i) {
        confirmedPayment($this->acme, $this->owner, $this->femi, (string) (1_000 * $i));
    }

    expect($queries())->toBe($before);
});

it('shows the receipt as printed and links it from its payment', function () {
    receiptsPage($this->owner, "/{$this->first->id}/document")->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('OFFICIAL RECEIPT')->assertSee($this->first->number)->assertSee('Kemi Adebayo')->assertSee('+2348030000001')->assertSee('One hundred thousand naira only');

    paymentsPage($this->owner, '/'.$this->first->payment_id)->assertOk()->assertSee('data-test="payment-receipt"', false)->assertSee($this->first->number);
    receiptsPage($this->owner, "/{$this->first->id}")->assertSee('data-test="receipt-payment"', false);

    // No VOID copy before a void.
    receiptsPage($this->owner, "/{$this->first->id}/void.pdf")->assertNotFound();
    receiptsPage($this->owner, "/{$this->first->id}")->assertDontSee('data-test="receipt-void-pdf"', false);
});
