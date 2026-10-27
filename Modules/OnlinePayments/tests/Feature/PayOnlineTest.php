<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Enums\BillStatus;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Enums\TransactionStatus;
use Modules\OnlinePayments\Jobs\SettlePaystackTransaction;
use Modules\OnlinePayments\PayOnline\PaystackOption;
use Modules\Payments\Enums\PaymentStatus;

require_once __DIR__.'/helpers.php';

/*
 * P03-08: "Pay online" on a bill's public pay page (Billing's
 * PaymentOptions slot, P03-05c) through the organization's OWN Paystack
 * account (ONBOARDING_COMPLIANCE.md § 4.2): offered only while the
 * organization is connected and what the bill owes is within its
 * online-payment cap (`organizations.online_payment_cap_kobo`, standard
 * ₦1,000,000); above it, bank details only. The transaction is started
 * with the organization's key, and Paystack sends the payer back to the
 * pay page.
 */

beforeEach(function () {
    onlinePaymentsWorld($this);
    [, $this->acmeSecret] = paystackKeysOf('acme-homes');
});

afterEach(fn () => billsFreshRequest());

function setOnlineCap(Organization $organization, User $owner, ?int $naira): void
{
    inOrganization($organization, fn () => app(PaystackConnections::class)->setCap($owner, $naira === null ? null : Money::naira($naira)));
}

describe('the pay page', function () {
    it('offers "Pay online" with the amount owed once the organization has connected its own Paystack', function () {
        payPage($this->bill->payment_reference)->assertOk()->assertDontSee('data-test="paystack-pay"', false)->assertDontSee('Pay online');

        connectPaystack($this->paystack, $this->acme, $this->owner);

        $page = payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('data-test="pay-online"', false)
            ->assertSee('data-test="paystack-pay"', false)
            ->assertSee('Pay ₦850,000 online')
            // Bank transfer stays offered beside it.
            ->assertSee('0123456789');
        preg_match('/href="([^"]+)" data-test="paystack-pay"/', $page->getContent(), $link);
        expect(html_entity_decode($link[1]))->toContain("/pay/{$this->bill->payment_reference}/online?")->toContain('signature=')->toContain('organization='.$this->acme->id)
            // Still no script on the pay page.
            ->and($page->getContent())->not->toContain('<script');

        // Another organization's bills are not offered our Paystack.
        payPage($this->foreign->payment_reference)->assertOk()->assertDontSee('data-test="paystack-pay"', false);
    });

    it('acceptance: shows a bill over the cap with bank details only', function () {
        connectPaystack($this->paystack, $this->acme, $this->owner);
        setOnlineCap($this->acme, $this->owner, 500_000);

        $page = payPage($this->bill->payment_reference)->assertOk()
            ->assertSee('0123456789')
            ->assertSee($this->bill->payment_reference)
            ->assertDontSee('data-test="pay-online"', false)
            ->assertDontSee('data-test="paystack-pay"', false);
        expect($page->getContent())->not->toContain('/online?');

        // The online page itself sends such a bill back to its pay page, and nothing is started.
        payOnlinePage(payOnlineLink($this->acme, $this->bill))->assertRedirect("/pay/{$this->bill->payment_reference}");
        freshRequest();
        $this->post(payOnlineLink($this->acme, $this->bill), ['email' => 'payer@example.com'])->assertRedirect("/pay/{$this->bill->payment_reference}");
        expect(onlinePaymentsOf($this->acme))->toBeEmpty()
            ->and($this->paystack->requestsTo('/transaction/initialize'))->toBeEmpty();

        // Exactly at the cap is offered; the standard cap (₦1,000,000) applies when none is set.
        setOnlineCap($this->acme, $this->owner, 850_000);
        payPage($this->bill->payment_reference)->assertSee('data-test="paystack-pay"', false);
        setOnlineCap($this->acme, $this->owner, null);
        payPage($this->bill->payment_reference)->assertSee('data-test="paystack-pay"', false);

        // A bill over the standard cap: bank details only.
        $tenant = tenantIn($this->acme, ['first_name' => 'Dayo', 'last_name' => 'Okon', 'phone' => '+2348039990000', 'email' => 'dayo.okon@example.com']);
        activeLease($this->acme, $this->owner, unitIn($this->acme, buildingIn($this->acme, ['name' => 'Cedar House', 'slug' => 'cedar-house'])), $tenant, ['rent' => '1,500,000']);
        runBillGeneration();
        $large = billsOfTenant($this->acme, $tenant)->sole();
        payPage($large->payment_reference)->assertOk()->assertSee('0123456789')->assertDontSee('data-test="paystack-pay"', false);

        // What still owes within the cap after a part payment is offered again.
        confirmedPayment($this->acme, $this->owner, $tenant, '700,000', ['billId' => (string) $large->id]);
        payPage($large->payment_reference)->assertOk()->assertSee('Pay ₦800,000 online');
    });

    it('offers nothing while the keys are untested, refused, or disconnected, nor for a paid or void bill', function () {
        $this->paystack->down = true;
        connectPaystack($this->paystack, $this->acme, $this->owner);
        expect(paystackConnectionOf($this->acme)->status->value)->toBe('pending_test');
        payPage($this->bill->payment_reference)->assertDontSee('data-test="paystack-pay"', false);

        $this->paystack->down = false;
        inOrganization($this->acme, fn () => app(PaystackConnections::class)->test($this->owner));
        payPage($this->bill->payment_reference)->assertSee('data-test="paystack-pay"', false);

        $this->paystack->forget($this->acmeSecret);
        inOrganization($this->acme, fn () => app(PaystackConnections::class)->test($this->owner));
        expect(paystackConnectionOf($this->acme)->status->value)->toBe('failing');
        payPage($this->bill->payment_reference)->assertDontSee('data-test="paystack-pay"', false);

        connectPaystack($this->paystack, $this->acme, $this->owner);
        inOrganization($this->acme, fn () => app(PaystackConnections::class)->disconnect($this->owner));
        payPage($this->bill->payment_reference)->assertDontSee('data-test="paystack-pay"', false);

        connectPaystack($this->paystack, $this->acme, $this->owner);
        confirmedPayment($this->acme, $this->owner, $this->kemi, '850,000', ['billId' => (string) $this->bill->id]);
        payPage($this->bill->payment_reference)->assertOk()->assertSee('This bill is paid')->assertDontSee('data-test="paystack-pay"', false);
    });
});

describe('paying online', function () {
    beforeEach(fn () => connectPaystack($this->paystack, $this->acme, $this->owner));

    it('shows the amount and asks for an email, then starts the transaction with the organization\'s key and sends the payer to Paystack', function () {
        $link = payOnlineLink($this->acme, $this->bill);

        $page = payOnlinePage($link)->assertOk()
            ->assertSee('Pay Adeyemi Realty online')
            ->assertSee('₦850,000')
            ->assertSee($this->bill->number)
            ->assertDontSee('Kemi')
            ->assertDontSee('+2348031112222')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        expect($page->headers->get('Content-Security-Policy'))->toContain("form-action 'self' https://checkout.paystack.com")->toContain("script-src 'none'")
            ->and($page->getContent())->not->toContain('<script');

        freshRequest();
        $response = $this->post($link, ['email' => 'payer@example.com'])->assertStatus(303);
        $transaction = onlinePaymentsOf($this->acme)->sole();
        $initialize = $this->paystack->requestsTo('/transaction/initialize')[0];

        expect($response->headers->get('Location'))->toStartWith('https://checkout.paystack.com/')
            ->and($initialize['secret'])->toBe($this->acmeSecret)
            ->and($initialize['body']['amount'])->toBe('85000000')
            ->and($initialize['body']['currency'])->toBe('NGN')
            ->and($initialize['body']['email'])->toBe('payer@example.com')
            ->and($initialize['body']['reference'])->toBe($transaction->reference)
            ->and($initialize['body']['callback_url'])->toBe(url("/pay/{$this->bill->payment_reference}/online/return"))
            ->and($initialize['body']['metadata']['payment_reference'])->toBe($this->bill->payment_reference)
            ->and($transaction->reference)->toMatch('/^GPO-[0-9A-F]{20}$/')
            ->and($transaction->bill_id)->toBe($this->bill->id)
            ->and($transaction->tenant_profile_id)->toBe($this->kemi->id)
            ->and($transaction->amount_kobo->kobo)->toBe(85_000_000)
            ->and($transaction->status)->toBe(TransactionStatus::Initialized)
            ->and($transaction->mode->value)->toBe('live');
    });

    it('settles the payment when the payer comes back from Paystack, then shows the bill paid', function () {
        Queue::fake([SettlePaystackTransaction::class]);
        $transaction = startOnlinePayment($this->acme, $this->bill);
        $this->paystack->pays($transaction->reference);

        payOnlinePage("/pay/{$this->bill->payment_reference}/online/return?trxref={$transaction->reference}&reference={$transaction->reference}")
            ->assertRedirect("/pay/{$this->bill->payment_reference}");
        Queue::assertPushedOn('billing', SettlePaystackTransaction::class, fn (SettlePaystackTransaction $job): bool => $job->reference === $transaction->reference && $job->organizationId === $this->acme->id);
        Queue::pushed(SettlePaystackTransaction::class)->each(fn (SettlePaystackTransaction $job) => app()->call([$job, 'handle']));

        expect(paymentsOf($this->acme)->sole()->status)->toBe(PaymentStatus::Confirmed)
            ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid);
        payPage($this->bill->payment_reference)->assertSee('This bill is paid');

        // A made-up or foreign return queues nothing and just shows the pay page.
        connectPaystack($this->paystack, $this->other, $this->otherOwner);
        $theirs = startOnlinePayment($this->other, $this->foreign);
        foreach (['GPO-00000000000000000000', 'nonsense', $theirs->reference] as $reference) {
            payOnlinePage("/pay/{$this->bill->payment_reference}/online/return?reference={$reference}")->assertRedirect("/pay/{$this->bill->payment_reference}");
        }
        Queue::assertPushed(SettlePaystackTransaction::class, 1);
    });

    it('answers 404 for a missing, tampered, expired or other organization\'s signed address', function () {
        $link = payOnlineLink($this->acme, $this->bill);

        payOnlinePage("/pay/{$this->bill->payment_reference}/online")->assertNotFound();
        payOnlinePage(str_replace('organization='.$this->acme->id, 'organization='.$this->other->id, $link))->assertNotFound();
        payOnlinePage(str_replace($this->bill->payment_reference, $this->foreign->payment_reference, $link))->assertNotFound();
        // Correctly signed for the other organization, with our bill's reference: their organization has no such bill.
        payOnlinePage(PaystackOption::link($this->bill->payment_reference, $this->other->id))->assertNotFound();
        freshRequest();
        $this->post("/pay/{$this->bill->payment_reference}/online", ['email' => 'payer@example.com'])->assertNotFound();

        $this->travel(13)->hours();
        payOnlinePage($link)->assertNotFound();
        expect(onlinePaymentsOf($this->acme))->toBeEmpty()->and(onlinePaymentsOf($this->other))->toBeEmpty();
    });

    it('asks again for a missing email, and says so when Paystack refuses to start, keeping that attempt as failed', function () {
        $link = payOnlineLink($this->acme, $this->bill);

        freshRequest();
        $this->from($link)->post($link, ['email' => 'not-an-email'])->assertRedirect($link)->assertSessionHasErrors('email');
        expect(onlinePaymentsOf($this->acme))->toBeEmpty();

        $this->paystack->refuseInitialize = true;
        freshRequest();
        $this->from($link)->post($link, ['email' => 'payer@example.com'])->assertRedirect($link)->assertSessionHasErrors(['email' => 'Paystack could not start the payment just now. Try again in a few minutes, or pay by bank transfer.']);
        expect(onlinePaymentsOf($this->acme)->sole()->status)->toBe(TransactionStatus::Failed);
    });

    it('limits starting payments to 10 a minute per address', function () {
        $link = payOnlineLink($this->acme, $this->bill);

        foreach (range(1, 10) as $attempt) {
            freshRequest();
            $this->post($link, ['email' => 'payer@example.com'])->assertStatus(303);
        }
        freshRequest();
        $this->post($link, ['email' => 'payer@example.com'])->assertStatus(429);
    });

    it('never puts the organization\'s keys on any public page', function () {
        $connection = paystackConnectionOf($this->acme);
        [$public, $secret] = paystackKeysOf('acme-homes');

        foreach ([payPage($this->bill->payment_reference), payOnlinePage(payOnlineLink($this->acme, $this->bill))] as $page) {
            expect($page->getContent())->not->toContain($secret)->not->toContain($public)->not->toContain($connection->webhook_token);
        }
    });
});

it('lets tenants of a suspended organization still pay online: the money goes to the agency', function () {
    connectPaystack($this->paystack, $this->acme, $this->owner);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    payPage($this->bill->payment_reference)->assertSee('data-test="paystack-pay"', false);
    freshRequest();
    $this->post(payOnlineLink($this->acme, $this->bill), ['email' => 'payer@example.com'])->assertStatus(303);
    expect(onlinePaymentsOf($this->acme))->toHaveCount(1);
});
