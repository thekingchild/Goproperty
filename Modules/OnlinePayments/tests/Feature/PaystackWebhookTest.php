<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Enums\BillStatus;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Enums\TransactionStatus;
use Modules\OnlinePayments\Enums\WebhookOutcome;
use Modules\OnlinePayments\Jobs\ProcessPaystackWebhook;
use Modules\OnlinePayments\Models\PaystackWebhookLog;
use Modules\OnlinePayments\Paystack\PaystackUnavailable;
use Modules\OnlinePayments\Transactions\PaystackTransactions;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;

require_once __DIR__.'/helpers.php';

/*
 * P03-08: Paystack's webhook for an organization's OWN account,
 * /webhooks/paystack/{webhook_token} (ONBOARDING_COMPLIANCE.md § 4.2,
 * orchestrator decision 2; BILLING_PAYMENTS.md § 4.2–4.3; ARCHITECTURE.md
 * § 10; AGENT.md § 3 rules 6 and 8): HMAC-SHA512 of the raw body with THAT
 * organization's secret key, the raw body kept in `webhook_log` before
 * processing, a fast 2xx with processing on the `billing` queue,
 * verify-by-fetch with the organization's key, the amount checked against
 * what was asked for the bill, and auto-confirmation through the Payments
 * module's PaymentRecorder, once per Paystack reference.
 */

beforeEach(function () {
    onlinePaymentsWorld($this);
    connectPaystack($this->paystack, $this->acme, $this->owner);
    connectPaystack($this->paystack, $this->other, $this->otherOwner);
    [, $this->acmeSecret] = paystackKeysOf('acme-homes');
    [, $this->otherSecret] = paystackKeysOf('other-realty');
    $this->transaction = startOnlinePayment($this->acme, $this->bill);
    $this->paystack->pays($this->transaction->reference);
});

afterEach(fn () => billsFreshRequest());

describe('acceptance: a webhook signed with the wrong organization\'s key is rejected', function () {
    it('rejects and keeps a delivery to our address signed with another organization\'s secret key, and records nothing', function () {
        $payload = chargeSuccess($this->transaction->reference, 85_000_000);

        paystackWebhook(webhookPathOf($this->acme), $payload, $this->otherSecret)->assertStatus(401);

        $log = webhookLogOf($this->acme)->sole();
        expect($log->signature_valid)->toBeFalse()
            ->and($log->outcome)->toBe(WebhookOutcome::Rejected)
            ->and($log->processed_at)->not->toBeNull()
            ->and($log->payload)->toBe((string) json_encode($payload))
            ->and($log->event)->toBe('charge.success')
            ->and($log->reference)->toBe($this->transaction->reference)
            ->and($log->provider)->toBe('paystack')
            ->and(webhookLogOf($this->other))->toBeEmpty()
            ->and(paymentsOf($this->acme))->toBeEmpty()
            ->and(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Initialized)
            ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(0)
            // Nothing was asked of Paystack: an unsigned claim is never verified, let alone recorded.
            ->and($this->paystack->requestsTo('/transaction/verify'))->toBeEmpty();
    });

    it('rejects GoProperty\'s own key, a forger\'s key, a missing or garbled signature, and a body changed after signing', function () {
        $payload = chargeSuccess($this->transaction->reference, 85_000_000);
        $raw = (string) json_encode($payload);
        $signed = hash_hmac('sha512', $raw, $this->acmeSecret);

        paystackWebhook(webhookPathOf($this->acme), $payload, 'sk_live_goproperty_platform_key')->assertStatus(401);
        paystackWebhook(webhookPathOf($this->acme), $payload, 'sk_live_'.str_repeat('f', 40))->assertStatus(401);
        paystackWebhook(webhookPathOf($this->acme), $payload, null)->assertStatus(401);
        paystackWebhook(webhookPathOf($this->acme), $payload, null, ['HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-signature'])->assertStatus(401);
        // The signature covers the raw bytes: the same JSON re-encoded (pretty-printed) or with a changed amount fails.
        paystackWebhook(webhookPathOf($this->acme), (string) json_encode($payload, JSON_PRETTY_PRINT), null, ['HTTP_X_PAYSTACK_SIGNATURE' => $signed])->assertStatus(401);
        paystackWebhook(webhookPathOf($this->acme), str_replace('85000000', '1', $raw), null, ['HTTP_X_PAYSTACK_SIGNATURE' => $signed])->assertStatus(401);

        expect(webhookLogOf($this->acme))->toHaveCount(6)
            ->and(webhookLogOf($this->acme)->every(fn (PaystackWebhookLog $log): bool => ! $log->signature_valid && $log->outcome === WebhookOutcome::Rejected))->toBeTrue()
            ->and(paymentsOf($this->acme))->toBeEmpty();

        // The right key over the exact bytes is accepted (and the uppercase hex Paystack may send too).
        paystackWebhook(webhookPathOf($this->acme), $raw, null, ['HTTP_X_PAYSTACK_SIGNATURE' => strtoupper($signed)])->assertOk()->assertJson(['received' => true]);
        expect(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed);
    });

    it('keeps each organization\'s deliveries apart: their key on their address works, ours on theirs does not', function () {
        $theirs = startOnlinePayment($this->other, $this->foreign);
        $this->paystack->pays($theirs->reference);

        paystackWebhook(webhookPathOf($this->other), chargeSuccess($theirs->reference, 70_000_000), $this->acmeSecret)->assertStatus(401);
        expect(paymentsOf($this->other))->toBeEmpty();

        paystackWebhook(webhookPathOf($this->other), chargeSuccess($theirs->reference, 70_000_000), $this->otherSecret)->assertOk();
        expect(paymentsOf($this->other)->sole()->status)->toBe(PaymentStatus::Confirmed)
            ->and(paymentsOf($this->acme))->toBeEmpty()
            ->and(webhookLogOf($this->other))->toHaveCount(2)
            ->and(webhookLogOf($this->acme))->toBeEmpty();
    });
});

describe('acceptance: a replayed webhook creates one payment', function () {
    it('confirms the payment once, however often Paystack delivers the event, and the return from checkout changes nothing', function () {
        $payload = chargeSuccess($this->transaction->reference, 85_000_000);

        foreach (range(1, 3) as $delivery) {
            paystackWebhook(webhookPathOf($this->acme), $payload, $this->acmeSecret)->assertOk();
        }
        // The payer comes back from Paystack's checkout afterwards: settled already.
        payOnlinePage("/pay/{$this->bill->payment_reference}/online/return?trxref={$this->transaction->reference}&reference={$this->transaction->reference}")
            ->assertRedirect("/pay/{$this->bill->payment_reference}");
        inOrganization($this->acme, fn () => app(PaystackTransactions::class)->settle($this->transaction->reference));

        $payment = paymentsOf($this->acme)->sole();
        expect($payment->status)->toBe(PaymentStatus::Confirmed)
            ->and($payment->amount_kobo->kobo)->toBe(85_000_000)
            ->and($payment->method)->toBe(PaymentMethod::Paystack)
            ->and($payment->source)->toBe(PaymentSource::PaystackWebhook)
            ->and($payment->paystack_reference)->toBe($this->transaction->reference)
            ->and($payment->idempotency_key)->toBe('paystack:'.$this->transaction->reference)
            ->and($payment->confirmed_by_user_id)->toBeNull()
            ->and($payment->bill_id)->toBe($this->bill->id)
            ->and(allocationsOf($this->acme, $payment))->toEqual([$this->bill->id => 85_000_000])
            ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid)
            ->and(receiptsOf($this->acme))->toHaveCount(1)
            ->and(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Confirmed)
            ->and(storedTransaction($this->acme, $this->transaction)->payment_id)->toBe($payment->id)
            ->and(webhookLogOf($this->acme)->pluck('outcome')->all())->toBe([WebhookOutcome::Confirmed, WebhookOutcome::Duplicate, WebhookOutcome::Duplicate])
            ->and(AuditEntry::query()->where('event', 'payment.confirmed')->count())->toBe(1);
    });

    it('creates one payment even when a replay finds the transaction not yet marked settled (the recorder\'s key and the unique reference hold)', function () {
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();

        // As if the transaction row had been left unsettled by a crash after the payment committed.
        DB::table('paystack_transactions')->where('id', $this->transaction->id)->update(['status' => 'initialized', 'payment_id' => null]);
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();

        expect(paymentsOf($this->acme))->toHaveCount(1)
            ->and(receiptsOf($this->acme))->toHaveCount(1)
            ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(85_000_000)
            ->and(storedTransaction($this->acme, $this->transaction)->payment_id)->toBe(paymentsOf($this->acme)->sole()->id);
    });
});

describe('fast 2xx, then processing on the queue', function () {
    it('keeps the raw body and answers 200 at once, with no session or cookies, then processes on the billing queue', function () {
        Queue::fake([ProcessPaystackWebhook::class]);
        $raw = (string) json_encode(chargeSuccess($this->transaction->reference, 85_000_000));

        $response = paystackWebhook(webhookPathOf($this->acme), $raw, $this->acmeSecret)->assertOk();

        expect($response->headers->getCookies())->toBeEmpty()
            ->and(paymentsOf($this->acme))->toBeEmpty()
            ->and(webhookLogOf($this->acme)->sole()->payload)->toBe($raw)
            ->and(webhookLogOf($this->acme)->sole()->processed_at)->toBeNull()
            ->and(paystackConnectionOf($this->acme)->last_event_at)->not->toBeNull();
        Queue::assertPushedOn('billing', ProcessPaystackWebhook::class, fn (ProcessPaystackWebhook $job): bool => $job->organizationId === $this->acme->id);

        // The queue worker (no organization context, as on the host) runs it.
        Queue::pushed(ProcessPaystackWebhook::class)->each(fn (ProcessPaystackWebhook $job) => app()->call([$job, 'handle']));

        expect(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed)
            ->and(webhookLogOf($this->acme)->sole()->outcome)->toBe(WebhookOutcome::Confirmed);
    });

    it('needs no CSRF token and starts no session, while the rest of the site still does', function () {
        expect(app('router')->getRoutes()->getByName('online-payments.webhook')->gatherMiddleware())->not->toContain('web');

        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)
            ->assertOk()->assertCookieMissing(config('session.cookie'));
    });

    it('refuses a body far larger than any Paystack event, keeping nothing', function () {
        $huge = chargeSuccess($this->transaction->reference, 85_000_000, ['padding' => str_repeat('x', 70_000)]);

        paystackWebhook(webhookPathOf($this->acme), $huge, $this->acmeSecret)->assertStatus(413);
        expect(webhookLogOf($this->acme))->toBeEmpty();
    });
});

describe('unknown webhook tokens', function () {
    it('answers the same plain 404 as any unknown address for an unknown, malformed, rotated or disconnected token, keeping nothing', function () {
        $unknown = paystackWebhook('/webhooks/paystack/'.str_repeat('a', 48), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertNotFound();
        $malformed = paystackWebhook('/webhooks/paystack/short', chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertNotFound();
        $nothing = paystackWebhook('/webhooks/nothing-here', [], null)->assertNotFound();

        expect($unknown->getContent())->toBe($nothing->getContent())
            ->and($malformed->getContent())->toBe($nothing->getContent())
            ->and($unknown->getContent())->not->toContain('Adeyemi')->not->toContain('paystack')
            ->and(DB::table('webhook_log')->count())->toBe(0);

        $old = webhookPathOf($this->acme);
        inOrganization($this->acme, fn () => app(PaystackConnections::class)->rotateWebhookToken($this->owner));
        paystackWebhook($old, chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertNotFound();
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();

        $path = webhookPathOf($this->other);
        inOrganization($this->other, fn () => app(PaystackConnections::class)->disconnect($this->otherOwner));
        paystackWebhook($path, chargeSuccess('GPO-00000000000000000000', 70_000_000), $this->otherSecret)->assertNotFound();
        expect(webhookLogOf($this->other))->toBeEmpty();
    });
});

describe('verify by fetch, with the organization\'s key', function () {
    it('records what Paystack says when asked, never what the webhook claims', function () {
        // A correctly signed event claiming far more than was paid: Paystack's own answer (₦850,000) counts.
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 999_999_900), $this->acmeSecret)->assertOk();

        $verify = $this->paystack->requestsTo('/transaction/verify/'.$this->transaction->reference);
        expect($verify)->toHaveCount(1)
            ->and($verify[0]['secret'])->toBe($this->acmeSecret)
            ->and(paymentsOf($this->acme)->sole()->amount_kobo->kobo)->toBe(85_000_000)
            ->and(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed)
            // GoProperty's own key never touches the organization's money.
            ->and(collect($this->paystack->requests)->pluck('secret')->unique()->values()->all())->not->toContain('sk_test_goproperty_platform');
    });

    it('acceptance: records nothing when Paystack cannot be asked, and processes the delivery once it can', function () {
        Queue::fake([ProcessPaystackWebhook::class]);
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();
        $job = Queue::pushed(ProcessPaystackWebhook::class)->sole();
        $this->paystack->down = true;

        expect(fn () => app()->call([$job, 'handle']))->toThrow(PaystackUnavailable::class);
        expect(paymentsOf($this->acme))->toBeEmpty()
            ->and(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Initialized)
            ->and(webhookLogOf($this->acme)->sole()->processed_at)->toBeNull()
            ->and(webhookLogOf($this->acme)->sole()->processing_error)->toContain('503')
            ->and($job->tries)->toBe(5)
            ->and($job->backoff)->toBe([30, 120, 600, 1800]);

        $this->paystack->down = false;
        app()->call([$job, 'handle']);
        expect(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed)
            ->and(webhookLogOf($this->acme)->sole()->processing_error)->toBeNull();
    });

    it('acceptance: records nothing when Paystack says the charge failed, was abandoned, or knows no such transaction', function () {
        $this->paystack->pays($this->transaction->reference, status: 'abandoned');
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();
        expect(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Initialized);

        $this->paystack->pays($this->transaction->reference, status: 'failed');
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();
        expect(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Failed);

        $second = startOnlinePayment($this->acme, $this->bill);
        unset($this->paystack->transactions[$second->reference]);
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($second->reference, 85_000_000), $this->acmeSecret)->assertOk();

        expect(paymentsOf($this->acme))->toBeEmpty()
            ->and(storedTransaction($this->acme, $second)->status)->toBe(TransactionStatus::Initialized)
            ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(0)
            ->and(webhookLogOf($this->acme)->pluck('outcome')->all())->toBe([WebhookOutcome::NotPaid, WebhookOutcome::NotPaid, WebhookOutcome::NotPaid]);
    });

    it('ignores events it does not act on, and charges GoProperty never started', function () {
        paystackWebhook(webhookPathOf($this->acme), ['event' => 'transfer.success', 'data' => ['reference' => $this->transaction->reference]], $this->acmeSecret)->assertOk();
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess('T-SHOP-SALE-1', 5_000_000), $this->acmeSecret)->assertOk();
        paystackWebhook(webhookPathOf($this->acme), 'not json', $this->acmeSecret)->assertOk();

        expect(webhookLogOf($this->acme)->pluck('outcome')->all())->toBe([WebhookOutcome::Ignored, WebhookOutcome::Ignored, WebhookOutcome::Ignored])
            ->and(paymentsOf($this->acme))->toBeEmpty()
            ->and($this->paystack->requestsTo('/transaction/verify'))->toBeEmpty();
    });
});

describe('the amount check', function () {
    it('acceptance: holds an amount mismatch for review: a payment waiting for a member, never confirmed', function () {
        $this->paystack->pays($this->transaction->reference, 50_000_000);

        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 50_000_000), $this->acmeSecret)->assertOk();

        $payment = paymentsOf($this->acme)->sole();
        $held = storedTransaction($this->acme, $this->transaction);
        expect($payment->status)->toBe(PaymentStatus::PendingConfirmation)
            ->and($payment->amount_kobo->kobo)->toBe(50_000_000)
            ->and($payment->method)->toBe(PaymentMethod::Paystack)
            ->and($payment->bill_id)->toBe($this->bill->id)
            ->and($payment->note)->toContain('Held for review')->toContain('₦500,000 was paid, but ₦850,000 was asked')
            ->and(allocationsOf($this->acme, $payment))->toBe([])
            ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(0)
            ->and(receiptsOf($this->acme))->toBeEmpty()
            ->and($held->status)->toBe(TransactionStatus::Held)
            ->and($held->payment_id)->toBe($payment->id)
            ->and($held->paid_amount_kobo?->kobo)->toBe(50_000_000)
            ->and(webhookLogOf($this->acme)->sole()->outcome)->toBe(WebhookOutcome::Held);

        $audit = AuditEntry::query()->where('event', 'paystack.payment_held')->sole();
        expect($audit->log_name)->toBe('online_payments')
            ->and($audit->properties['asked_kobo'])->toBe(85_000_000)
            ->and($audit->properties['paid_kobo'])->toBe(50_000_000);

        // A replay changes nothing; a member decides in the confirmation queue.
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 50_000_000), $this->acmeSecret)->assertOk();
        expect(paymentsOf($this->acme))->toHaveCount(1)->and(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::PendingConfirmation);
        paymentsPage($this->owner, '?status=pending_confirmation')->assertOk()->assertSee('data-payment="'.$payment->id.'"', false);
    });

    it('holds a payment the bill no longer needs (paid meanwhile, or voided), and money in another currency', function () {
        // The bill was paid by bank transfer while the tenant was at the checkout.
        confirmedPayment($this->acme, $this->owner, $this->kemi, '850,000', ['billId' => (string) $this->bill->id]);
        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();

        $held = paymentsOf($this->acme)->last();
        expect(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Held)
            ->and($held->status)->toBe(PaymentStatus::PendingConfirmation)
            ->and($held->note)->toContain('now owes ₦0, less than the ₦850,000 paid')
            ->and(storedBill($this->acme, $this->bill)->paid_kobo->kobo)->toBe(85_000_000);

        // Dollars: a kobo payment cannot say it. Kept aside for the owner, no payment.
        $tenant = tenantIn($this->acme, ['first_name' => 'Dayo', 'last_name' => 'Okon', 'phone' => '+2348039990000', 'email' => 'dayo.okon@example.com']);
        activeLease($this->acme, $this->owner, unitIn($this->acme, buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house'])), $tenant, ['rent' => '600,000']);
        runBillGeneration();
        $cedar = billsOfTenant($this->acme, $tenant)->sole();
        $dollars = startOnlinePayment($this->acme, $cedar);
        $this->paystack->pays($dollars->reference, 40_000, currency: 'USD');
        $before = paymentsOf($this->acme)->count();

        paystackWebhook(webhookPathOf($this->acme), chargeSuccess($dollars->reference, 40_000, ['currency' => 'USD']), $this->acmeSecret)->assertOk();
        expect(storedTransaction($this->acme, $dollars)->status)->toBe(TransactionStatus::Held)
            ->and(storedTransaction($this->acme, $dollars)->hold_reason)->toContain('Paid in USD, not naira')
            ->and(storedTransaction($this->acme, $dollars)->payment_id)->toBeNull()
            ->and(paymentsOf($this->acme))->toHaveCount($before);

        // The owner sees both on the settings page.
        $page = test()->actingAs($this->owner)->get('/o/acme-homes/settings/paystack')->assertOk();
        $page->assertSee('data-held="'.$dollars->reference.'"', false)->assertSee('data-held="'.$this->transaction->reference.'"', false)->assertSee('400.00 USD');
    });
});

it('cross-org probes: a reference of another organization is never settled through our address, nor ours through theirs', function () {
    $theirs = startOnlinePayment($this->other, $this->foreign);
    $this->paystack->pays($theirs->reference);

    // Their reference, correctly signed by us, on our address: not our transaction.
    paystackWebhook(webhookPathOf($this->acme), chargeSuccess($theirs->reference, 70_000_000), $this->acmeSecret)->assertOk();
    // Our reference, signed by them, on their address: not theirs either.
    paystackWebhook(webhookPathOf($this->other), chargeSuccess($this->transaction->reference, 85_000_000), $this->otherSecret)->assertOk();

    expect(paymentsOf($this->acme))->toBeEmpty()
        ->and(paymentsOf($this->other))->toBeEmpty()
        ->and(storedTransaction($this->other, $theirs)->status)->toBe(TransactionStatus::Initialized)
        ->and(storedTransaction($this->acme, $this->transaction)->status)->toBe(TransactionStatus::Initialized)
        ->and(webhookLogOf($this->acme)->sole()->outcome)->toBe(WebhookOutcome::Ignored)
        ->and(webhookLogOf($this->other)->sole()->outcome)->toBe(WebhookOutcome::Ignored)
        // Each organization's log shows only its own deliveries.
        ->and(webhookLogOf($this->acme)->pluck('reference')->all())->toBe([$theirs->reference])
        ->and(inOrganization($this->acme, fn () => PaystackWebhookLog::query()->find(webhookLogOf($this->other)->sole()->id)))->toBeNull();
});

it('records a verified payment for a suspended organization: the money is already in its own account', function () {
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    paystackWebhook(webhookPathOf($this->acme), chargeSuccess($this->transaction->reference, 85_000_000), $this->acmeSecret)->assertOk();

    expect(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed)
        ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid)
        ->and(receiptsOf($this->acme))->toHaveCount(1);
});
