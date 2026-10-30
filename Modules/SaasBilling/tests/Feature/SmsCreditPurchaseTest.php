<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\SmsCreditEntry;
use App\Models\User;
use App\Sms\Credits\CreditReason;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/sms-credit-helpers.php';

/*
 * P05-03: buying SMS credit bundles in the app through GoProperty's own
 * Paystack account. "Credits are added only when the payment is confirmed
 * (webhook/verify), exactly once per payment" (orchestrator default 5),
 * with BILLING_PAYMENTS.md § 4.2–4.3: verify by fetch, one payment per
 * reference, mismatches held, forged webhooks dropped and logged.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'signup_plan_key' => 'starter']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@adeyemi.ng']), $this->acme);
});

function smsInvoice(Organization $organization): PlatformInvoice
{
    return inOrganization($organization, fn (): PlatformInvoice => PlatformInvoice::query()->sole());
}

function smsPayment(Organization $organization): PlatformPayment
{
    return inOrganization($organization, fn (): PlatformPayment => PlatformPayment::query()->sole());
}

it('starts a checkout: a PR invoice with VAT, a pending payment and Paystack\'s checkout with GoProperty\'s key', function () {
    $http = platformPaystack();

    $response = buyCredits($this->owner, 500)->assertOk();

    expect(LivewireHttp::redirect($response))->toBe('https://checkout.paystack.com/sms500');
    $invoice = smsInvoice($this->acme);
    expect($invoice->only(['number', 'sms_credits', 'status']))->toEqual(['number' => 'PR-2026-000001', 'sms_credits' => 500, 'status' => InvoiceStatus::Open])
        ->and([$invoice->subtotal_kobo->kobo, $invoice->vat_kobo->kobo, $invoice->total_kobo->kobo])->toBe([300_000, 22_500, BUNDLE_500_KOBO])
        ->and(smsPayment($this->acme)->only(['paystack_reference', 'status', 'method', 'currency']))->toEqual(['paystack_reference' => FIRST_SMS_REFERENCE, 'status' => PlatformPaymentStatus::Pending, 'method' => 'paystack', 'currency' => 'NGN'])
        ->and(purchasedCredits($this->acme))->toBe(0);

    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer sk_test_goproperty_platform')
        && $request['amount'] === (string) BUNDLE_500_KOBO
        && $request['reference'] === FIRST_SMS_REFERENCE
        && $request['email'] === 'ada@adeyemi.ng'
        && $request['callback_url'] === url('/o/acme-homes/settings/sms-credits/paid'));
});

it('acceptance: adds the credits once when Paystack confirms, from the return and the webhook alike, replays included', function () {
    $http = platformPaystack(['status' => 'success']);
    buyCredits($this->owner, 500);

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits/paid?trxref='.FIRST_SMS_REFERENCE.'&reference='.FIRST_SMS_REFERENCE)
        ->assertRedirect('/o/acme-homes/settings/sms-credits')
        ->assertSessionHas('sms_credits_status', ['success', 'Payment received: the credits are in your balance.']);

    // The webhook arrives too, twice, and the buyer reloads the return page.
    platformWebhook()->assertOk();
    platformWebhook()->assertOk();
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)->assertRedirect();

    $purchases = inOrganization($this->acme, fn () => SmsCreditEntry::query()->where('reason', CreditReason::Purchase)->get());
    expect($purchases)->toHaveCount(1)
        ->and($purchases[0]->only(['credits', 'platform_invoice_id', 'note']))->toBe(['credits' => 500, 'platform_invoice_id' => smsInvoice($this->acme)->id, 'note' => 'Invoice PR-2026-000001'])
        ->and(purchasedCredits($this->acme))->toBe(500)
        ->and(smsInvoice($this->acme)->status)->toBe(InvoiceStatus::Paid)
        ->and(smsPayment($this->acme)->only(['status', 'paystack_channel']))->toEqual(['status' => PlatformPaymentStatus::Confirmed, 'paystack_channel' => 'card'])
        ->and(DB::table('webhook_log')->whereNull('organization_id')->orderBy('id')->pluck('outcome')->all())->toBe(['duplicate', 'duplicate'])
        ->and(DB::table('activity_log')->where('event', 'sms_credits.purchased')->count())->toBe(1);

    // The checkout and one verify by fetch (the query string and the webhook's body are never trusted);
    // once settled, the later calls stop before asking Paystack again.
    $http->assertSentCount(2);
});

it('adds the credits from the webhook alone when the buyer never comes back', function () {
    platformPaystack(['status' => 'success']);
    buyCredits($this->owner, 500);

    platformWebhook()->assertOk()->assertJson(['received' => true]);

    expect(purchasedCredits($this->acme))->toBe(500)
        ->and(DB::table('webhook_log')->whereNull('organization_id')->sole()->outcome)->toBe('confirmed');
});

it('keeps and rejects a webhook with a bad signature, and never processes it', function () {
    $http = platformPaystack(['status' => 'success']);
    buyCredits($this->owner, 500);

    platformWebhook(signature: hash_hmac('sha512', 'something else', 'sk_test_goproperty_platform'))->assertStatus(401);
    platformWebhook(signature: 'not-a-signature')->assertStatus(401);

    $logs = DB::table('webhook_log')->whereNull('organization_id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('signature_valid')->map(fn ($valid): bool => (bool) $valid)->all())->toBe([false, false])
        ->and($logs->pluck('outcome')->all())->toBe(['rejected', 'rejected'])
        ->and(purchasedCredits($this->acme))->toBe(0);
    $http->assertSentCount(1);
});

it('answers 404 at the webhook address while GoProperty has no Paystack key', function () {
    withoutPaystackKey();

    platformWebhook()->assertNotFound();
    expect(DB::table('webhook_log')->count())->toBe(0);
});

it('holds a payment that does not match the invoice and adds nothing', function (array $transaction, string $reason) {
    platformPaystack($transaction);
    buyCredits($this->owner, 500);

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)
        ->assertSessionHas('sms_credits_status', fn (array $status): bool => $status[0] === 'warning' && str_contains($status[1], 'does not match'));
    platformWebhook()->assertOk();

    expect(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Held)
        ->and(smsPayment($this->acme)->hold_reason)->toContain($reason)
        ->and(smsInvoice($this->acme)->status)->toBe(InvoiceStatus::Open)
        ->and(purchasedCredits($this->acme))->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'sms_credits.payment_held')->count())->toBe(1);

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits')->assertOk()->assertSee('Under review');
})->with([
    'less money' => [['amount' => 100_000], '₦1,000 was paid, but ₦3,225 was asked'],
    'another currency' => [['currency' => 'USD'], 'Paid in USD'],
    'live mode with a test key' => [['domain' => 'live'], 'live mode, not test'],
]);

it('voids the invoice of a failed payment, holds one paid after all, and leaves an abandoned checkout pending', function () {
    platformPaystack(['status' => 'abandoned']);
    buyCredits($this->owner, 500);
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)
        ->assertSessionHas('sms_credits_status', fn (array $status): bool => str_contains($status[1], 'has not confirmed'));
    expect(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Pending);

    platformPaystack(['status' => 'failed']);
    platformWebhook()->assertOk();
    expect(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Failed)
        ->and(smsInvoice($this->acme)->status)->toBe(InvoiceStatus::Void);

    // Paystack later says it was paid after all: held for staff, never lost and never applied by itself.
    platformPaystack(['status' => 'success']);
    platformWebhook()->assertOk();
    expect(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Held)
        ->and(smsPayment($this->acme)->hold_reason)->toContain('Invoice PR-2026-000001 is void')
        ->and(purchasedCredits($this->acme))->toBe(0);
});

it('finds only the organization\'s own payment on the return', function () {
    platformPaystack(['status' => 'success']);
    buyCredits($this->owner, 500);
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $outsider = joinAs(User::factory()->create(), $other);

    $this->actingAs($outsider)->get('/o/other-realty/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)
        ->assertRedirect('/o/other-realty/settings/sms-credits')
        ->assertSessionHas('sms_credits_status', ['warning', 'That payment was not found.']);
    $this->actingAs($outsider)->get('/o/acme-homes/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)->assertNotFound();

    expect(purchasedCredits($this->acme))->toBe(0)
        ->and(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Pending);
});

it('refuses to start a checkout for a bundle not offered, a buyer without an email, or when Paystack is down', function () {
    platformPaystack();

    expect(LivewireHttp::html(buyCredits($this->owner, 777)))->toContain('That bundle is no longer offered');

    $phoneOnly = joinAs(User::factory()->create(['email' => null, 'phone' => '+2348035550000']), $this->acme);
    expect(LivewireHttp::html(buyCredits($phoneOnly, 500)))->toContain('needs an email address');

    withPaystackKey();
    fakePaystack(['api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Service unavailable'], 503)]);
    expect(LivewireHttp::html(buyCredits($this->owner, 500)))->toContain('could not start the payment');

    expect(smsPayment($this->acme)->status)->toBe(PlatformPaymentStatus::Failed)
        ->and(smsInvoice($this->acme)->status)->toBe(InvoiceStatus::Void);
});

it('never takes real money outside production: a live key there offers no bundles', function () {
    config(['services.paystack.secret_key' => 'sk_live_goproperty_platform']);
    $http = fakePaystack([]);

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/sms-credits')->assertOk()
        ->assertSee('Buying SMS credits online is not available yet')
        ->assertDontSee('data-test="buy-500"', false);
    expect(LivewireHttp::html(buyCredits($this->owner, 500)))->toContain('not available yet');

    $http->assertNothingSent();
    expect(inOrganization($this->acme, fn (): int => PlatformInvoice::query()->count()))->toBe(0);
});

it('lets only owners buy: administrators see the credits, agents nothing', function () {
    platformPaystack();
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    $this->actingAs($admin)->get('/o/acme-homes/settings/sms-credits')->assertOk()
        ->assertSee('Only an owner of the organization can buy credits.')
        ->assertDontSee('data-test="buy-500"', false);
    buyCredits($admin, 500)->assertForbidden();
    $this->actingAs($agent)->get('/o/acme-homes/settings/sms-credits')->assertForbidden();
    $this->actingAs($agent)->get('/o/acme-homes/settings/sms-credits/paid?reference='.FIRST_SMS_REFERENCE)->assertForbidden();

    expect(inOrganization($this->acme, fn (): int => PlatformInvoice::query()->count()))->toBe(0);
});
