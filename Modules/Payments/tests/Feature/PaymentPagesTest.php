<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Enums\BillStatus;
use Modules\Payments\Enums\PaymentStatus;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-10a: the payment pages: record a payment (for a tenant or one of
 * their bills), the confirmation queue and the payments list with its
 * filters, search and export, and the payment's page with Confirm and
 * Reject. Search and filter tests give every row fixed names, phones,
 * emails and references.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->tunde = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348030000002', 'email' => 'tunde@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), $this->tunde, ['billingCycle' => 'monthly', 'rent' => '80,000', 'deposit' => '0']);
    runBillGeneration();
    $this->kemisBill = billsOfTenant($this->acme, $this->kemi)->sole();
});

afterEach(fn () => billsFreshRequest());

it('records a payment for a tenant\'s bill from the form, for the confirmation queue', function () {
    paymentsPage($this->owner, '/create?bill='.$this->kemisBill->id)->assertOk()->assertSee($this->kemisBill->number)->assertSee('Kemi Adebayo');
    $form = paymentsSnapshot($this->owner, '/create?bill='.$this->kemisBill->id);

    // Errors are shown on the form and nothing is saved.
    $refused = paymentsUpdate($this->owner, $form, ['amount' => 'ten thousand', 'method' => 'cash'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKeys(['amount', 'note'])
        ->and(paymentsOf($this->acme))->toHaveCount(0);

    $saved = paymentsUpdate($this->owner, $form, ['amount' => '100,000', 'reference' => 'GTB/0001', 'paidAt' => '2026-12-24'], [['save']])->assertOk();
    $payment = paymentsOf($this->acme)->sole();

    expect(LivewireHttp::redirect($saved))->toEndWith("/o/acme-homes/payments/{$payment->id}")
        ->and($payment->tenant_profile_id)->toBe($this->kemi->id)
        ->and($payment->bill_id)->toBe($this->kemisBill->id)
        ->and($payment->amount_kobo->kobo)->toBe(10_000_000)
        ->and($payment->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and($payment->paid_at->toDateString())->toBe('2026-12-24');

    paymentsPage($this->owner, "/{$payment->id}")->assertOk()
        ->assertSee('Waiting for confirmation')
        ->assertSee('data-test="planned-bill"', false)
        ->assertSee($this->kemisBill->number)
        ->assertSee('GTB/0001');
    expect(listedPayments(paymentsPage($this->owner, '?status=pending_confirmation')))->toBe([$payment->id]);
});

it('lists the chosen tenant\'s open bills on the form, and records and confirms at once', function () {
    $form = paymentsSnapshot($this->owner, '/create');
    $chosen = paymentsUpdate($this->owner, $form, ['tenantId' => (string) $this->tunde->id])->assertOk();
    expect(LivewireHttp::html($chosen))->toContain(billsOfTenant($this->acme, $this->tunde)->sole()->number)
        ->not->toContain($this->kemisBill->number);

    $saved = paymentsUpdate($this->owner, LivewireHttp::next($chosen), ['amount' => '90,000', 'method' => 'cash', 'note' => 'Paid at the estate office'], [['saveAndConfirm']])->assertOk();
    $payment = paymentsOf($this->acme)->sole();

    expect(LivewireHttp::redirect($saved))->toEndWith("/o/acme-homes/payments/{$payment->id}")
        ->and($payment->status)->toBe(PaymentStatus::Confirmed)
        ->and(billsOfTenant($this->acme, $this->tunde)->sole()->status)->toBe(BillStatus::Paid)
        ->and(creditOf($this->acme, $this->tunde)->kobo)->toBe(1_000_000);

    paymentsPage($this->owner, "/{$payment->id}")->assertOk()->assertSee('Confirmed')->assertSee('data-test="allocation"', false)->assertSee('data-test="credited"', false)->assertDontSee('data-test="confirm-payment"', false);
});

it('confirms and rejects payments from the payment page', function () {
    $first = recordPayment($this->acme, $this->owner, $this->kemi, '100,000');
    $second = recordPayment($this->acme, $this->owner, $this->tunde, '80,000');

    paymentsUpdate($this->owner, paymentsSnapshot($this->owner, "/{$first->id}"), ['note' => 'On the GTB statement'], [['confirm']])->assertOk()->assertSee('The payment is confirmed.');
    expect(storedPayment($this->acme, $first)->status)->toBe(PaymentStatus::Confirmed)
        ->and(storedPayment($this->acme, $first)->confirmation_note)->toBe('On the GTB statement')
        ->and(storedBill($this->acme, $this->kemisBill)->status)->toBe(BillStatus::Paid);

    $page = paymentsSnapshot($this->owner, "/{$second->id}");
    $refused = paymentsUpdate($this->owner, $page, ['reason' => ''], [['reject']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('reason');
    paymentsUpdate($this->owner, $page, ['reason' => 'Not on the statement'], [['reject']])->assertOk()->assertSee('The payment is rejected.');
    expect(storedPayment($this->acme, $second)->status)->toBe(PaymentStatus::Rejected);
    paymentsPage($this->owner, "/{$second->id}")->assertOk()->assertSee('Not on the statement')->assertDontSee('data-test="confirm-payment"', false);

    // A page opened before someone else confirmed shows the refusal and changes nothing.
    $third = recordPayment($this->acme, $this->owner, $this->kemi, '5,000');
    $stale = paymentsSnapshot($this->owner, "/{$third->id}");
    confirmPayment($this->acme, $this->owner, $third);
    paymentsUpdate($this->owner, $stale, [], [['confirm']])->assertForbidden();
    expect(allocationsOf($this->acme, $third))->toBe([]);
});

it('shows the confirmation queue oldest paid first, and every payment newest first, with filters and search', function () {
    $older = recordPayment($this->acme, $this->owner, $this->kemi, '10,000', ['paidAt' => '2026-12-20', 'reference' => 'ZEN/111']);
    $newer = recordPayment($this->acme, $this->owner, $this->tunde, '20,000', ['paidAt' => '2026-12-23', 'method' => 'pos', 'note' => 'POS at the office', 'payerName' => 'Bakare Ventures', 'payerType' => 'corporate']);
    $done = confirmedPayment($this->acme, $this->owner, $this->kemi, '30,000', ['paidAt' => '2026-12-24']);

    paymentsPage($this->owner)->assertSee('data-test="waiting-count"', false);
    expect(listedPayments(paymentsPage($this->owner, '?status=pending_confirmation')))->toBe([$older->id, $newer->id])
        ->and(listedPayments(paymentsPage($this->owner)))->toBe([$done->id, $newer->id, $older->id])
        ->and(listedPayments(paymentsPage($this->owner, '?status=confirmed')))->toBe([$done->id])
        ->and(listedPayments(paymentsPage($this->owner, '?method=pos')))->toBe([$newer->id])
        ->and(listedPayments(paymentsPage($this->owner, '?source=manual')))->toHaveCount(3)
        ->and(listedPayments(paymentsPage($this->owner, '?tenant='.$this->kemi->id)))->toBe([$done->id, $older->id])
        ->and(listedPayments(paymentsPage($this->owner, '?q=Adebayo')))->toBe([$done->id, $older->id])
        ->and(listedPayments(paymentsPage($this->owner, '?q=08030000002')))->toBe([$newer->id])
        ->and(listedPayments(paymentsPage($this->owner, '?q=Ventures')))->toBe([$newer->id])
        ->and(listedPayments(paymentsPage($this->owner, '?q=ZEN/111')))->toBe([$older->id])
        ->and(listedPayments(paymentsPage($this->owner, '?q=nobody-matches')))->toBe([]);

    // The queue button and "all" from the page itself.
    $queued = paymentsUpdate($this->owner, paymentsSnapshot($this->owner), [], [['showQueue']])->assertOk();
    expect(listedPayments(LivewireHttp::html($queued)))->toBe([$older->id, $newer->id]);
    paymentsPage($this->owner, '?q=nobody-matches')->assertSee('data-test="clear-filters"', false);
});

it('exports the payments the page shows, in Excel and CSV, with its filters', function () {
    recordPayment($this->acme, $this->owner, $this->kemi, '10,000.50', ['paidAt' => '2026-12-20', 'reference' => 'ZEN/111']);
    confirmedPayment($this->acme, $this->owner, $this->tunde, '80,000', ['paidAt' => '2026-12-24', 'payerName' => '=HYPERLINK("x")']);

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/payments', 'payments', $format);
        expect($rows[0])->toBe(['Paid on', 'Tenant', 'Amount', 'Method', 'Source', 'Status', 'Payer', 'Reference', 'Recorded on', 'Confirmed on', 'Rejected on', 'Rejection reason'])
            ->and(exportColumn($rows, 'Tenant'))->toBe(['Tunde Bakare', 'Kemi Adebayo'])
            ->and(exportColumn($rows, 'Amount'))->toBe(['80000.00', '10000.50'])
            ->and(exportColumn($rows, 'Paid on'))->toBe(['2026-12-24', '2026-12-20'])
            ->and(exportColumn($rows, 'Status'))->toBe(['Confirmed', 'Waiting for confirmation'])
            ->and(exportColumn($rows, 'Payer')[0])->toBe("'=HYPERLINK(\"x\")")
            ->and(exportColumn($rows, 'Confirmed on')[0])->toBe('2026-12-25 10:00');
    }

    $queue = exportRows($this->owner, '/payments', 'payments', 'csv', ['status' => 'pending_confirmation']);
    expect(exportColumn($queue, 'Reference'))->toBe(['ZEN/111']);
});

it('pages through payments without a query per row', function () {
    $queries = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += preg_match('/\b(payments|tenant_profiles|bills|payment_allocations)\b/', $query->sql) === 1 ? 1 : 0;
        });
        paymentsPage($this->owner)->assertOk();

        return $queries;
    };

    recordPayment($this->acme, $this->owner, $this->kemi, '1,000');
    $one = $queries();
    expect($one)->toBeGreaterThan(2);

    foreach (range(1, 20) as $n) {
        recordPayment($this->acme, $this->owner, $n % 2 === 0 ? $this->kemi : $this->tunde, (string) (1_000 + $n));
    }

    expect($queries())->toBe($one);
});
