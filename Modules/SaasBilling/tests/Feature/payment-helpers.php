<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Tests\Support\LivewireHttp;

/*
 * Helpers for paying subscription invoices through GoProperty's Paystack in
 * tests (P06-03; also the dunning ladder, P06-05). Need metering-helpers.php,
 * tests/Support/organization-settings.php, tests/Support/plans.php and
 * tests/Feature/Exports/helpers.php loaded.
 *
 * The usual world: Acme on Starter with 30 units; its trial ends on
 * 16 November, invoice PR-2026-000001 for 16–30 November: ₦12,000 + ₦900
 * VAT = ₦12,900, due 23 November.
 */

const FIRST_INVOICE = 'PR-2026-000001';

const FIRST_INVOICE_KOBO = 1_290_000;

/**
 * GoProperty's Paystack in test mode: every checkout starts, and verify
 * answers for each reference what $charges says ([status, amount] or
 * omitted: not found).
 *
 * @param  array<string, array{0: string, 1: int}>  $charges
 */
function subscriptionPaystack(array $charges = []): Factory
{
    withPaystackKey();

    return fakePaystack([
        'api.paystack.co/transaction/initialize' => fn (Request $request) => Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => 'https://checkout.paystack.com/'.$request['reference'], 'access_code' => 'x', 'reference' => $request['reference']]]),
        'api.paystack.co/transaction/verify/*' => function (Request $request) use ($charges) {
            $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH) ?: ''));

            if (! isset($charges[$reference])) {
                return Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404);
            }

            [$status, $amount] = $charges[$reference];

            return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                'reference' => $reference, 'status' => $status, 'amount' => $amount, 'currency' => 'NGN', 'domain' => 'test',
                'channel' => 'card', 'paid_at' => '2026-11-24T09:05:00.000Z', 'gateway_response' => 'Successful',
            ]]);
        },
    ]);
}

/** Presses "Pay" on an invoice on Settings → Subscription; returns the Livewire reply. */
function payInvoice(User $user, string $number = FIRST_INVOICE, string $slug = 'acme-homes'): TestResponse
{
    exportsFreshRequest();
    $page = settingsPage($user, 'subscription', $slug);
    exportsFreshRequest();

    return LivewireHttp::update(test()->actingAs($user), $page, [], [['pay', [$number]]]);
}

/** Paystack's webhook to GoProperty's own account for a subscription payment, signed unless told otherwise. */
function subscriptionWebhook(string $reference, string $event = 'charge.success', ?string $signature = null, int $amount = FIRST_INVOICE_KOBO): TestResponse
{
    $body = json_encode(['event' => $event, 'data' => ['reference' => $reference, 'amount' => $amount, 'currency' => 'NGN', 'status' => $event === 'charge.success' ? 'success' : 'failed']], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, 'sk_test_goproperty_platform'),
    ], $body);
}

function invoiceNumbered(Organization $organization, string $number): PlatformInvoice
{
    return inOrganization($organization, fn (): PlatformInvoice => PlatformInvoice::query()->where('number', $number)->sole());
}

/** @return list<PlatformPayment> */
function invoicePayments(Organization $organization, string $number): array
{
    return inOrganization($organization, fn (): array => array_values(PlatformPayment::query()->where('platform_invoice_id', invoiceNumbered($organization, $number)->id)->orderBy('id')->get()->all()));
}

function subscriptionOf(Organization $organization): Subscription
{
    return inOrganization($organization, fn (): Subscription => Subscription::query()->sole());
}
