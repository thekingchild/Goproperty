<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Models\Bill;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Models\PaystackTransaction;
use Modules\OnlinePayments\Models\PaystackWebhookLog;
use Modules\OnlinePayments\PayOnline\PaystackOption;
use Modules\OnlinePayments\Transactions\PaystackTransactions;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the OnlinePayments module's tests (P03-08). Bills come from
 * the Billing module's helpers (made by the scheduled `bills-generate`
 * job), payments are read with the Payments module's helpers. Paystack is
 * a fake server (FakePaystackServer) behind Http::fake(): no test reaches
 * the network, and stray requests are refused (none of these tests renders
 * a PDF through Gotenberg: the PDF jobs are held back).
 */

require_once __DIR__.'/../../../Payments/tests/Feature/helpers.php';
require_once __DIR__.'/../../../Payments/tests/Feature/receipt-helpers.php';
require_once __DIR__.'/../../../Billing/tests/Feature/invoice-helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/** Fixed Paystack keys per organization: [public, secret]. */
function paystackKeysOf(string $who, string $mode = 'live'): array
{
    $seed = substr(hash('sha256', $who.$mode), 0, 40);

    return ["pk_{$mode}_{$seed}", "sk_{$mode}_".strrev($seed)];
}

/**
 * Paystack as the tests see it: the secret keys it knows (key → mode),
 * the transactions started with each key, and every request it got.
 */
final class FakePaystackServer
{
    /** @var array<string, string> secret key => test|live */
    public array $keys = [];

    /** @var array<string, array{secret: string, amount: int, currency: string, status: string, domain: string, email: string, callback: string, paid_at: string|null, channel: string|null}> */
    public array $transactions = [];

    /** @var list<array{method: string, path: string, secret: string, body: array<string, mixed>}> */
    public array $requests = [];

    /** Answer 503 to everything (Paystack down). */
    public bool $down = false;

    /** Refuse initializing (a 400 with a message). */
    public bool $refuseInitialize = false;

    public function know(string $secret, string $mode = 'live'): self
    {
        $this->keys[$secret] = $mode;

        return $this;
    }

    public function forget(string $secret): self
    {
        unset($this->keys[$secret]);

        return $this;
    }

    /** The payer paid at Paystack: what verify will answer from now on. */
    public function pays(string $reference, ?int $kobo = null, string $status = 'success', string $currency = 'NGN', string $channel = 'card'): self
    {
        $this->transactions[$reference]['status'] = $status;
        $this->transactions[$reference]['amount'] = $kobo ?? $this->transactions[$reference]['amount'];
        $this->transactions[$reference]['currency'] = $currency;
        $this->transactions[$reference]['paid_at'] = '2026-12-25T09:30:00.000Z';
        $this->transactions[$reference]['channel'] = $channel;

        return $this;
    }

    /** @return list<array{method: string, path: string, secret: string, body: array<string, mixed>}> */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => str_starts_with($request['path'], $path)));
    }

    public function handle(Request $request): mixed
    {
        $secret = trim(str_replace('Bearer', '', $request->header('Authorization')[0] ?? ''));
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = $request->data();
        $this->requests[] = ['method' => $request->method(), 'path' => $path, 'secret' => $secret, 'body' => is_array($body) ? $body : []];

        if ($this->down) {
            return Http::response(['status' => false, 'message' => 'Service unavailable'], 503);
        }

        if (! isset($this->keys[$secret])) {
            return Http::response(['status' => false, 'message' => 'Invalid key'], 401);
        }

        if ($request->method() === 'GET' && $path === '/transaction') {
            return Http::response(['status' => true, 'message' => 'Transactions retrieved', 'data' => []]);
        }

        if ($request->method() === 'POST' && $path === '/transaction/initialize') {
            if ($this->refuseInitialize) {
                return Http::response(['status' => false, 'message' => 'Invalid Email Address Passed'], 400);
            }

            $reference = (string) ($body['reference'] ?? '');
            $this->transactions[$reference] = [
                'secret' => $secret,
                'amount' => (int) ($body['amount'] ?? 0),
                'currency' => (string) ($body['currency'] ?? 'NGN'),
                'status' => 'abandoned',
                'domain' => $this->keys[$secret],
                'email' => (string) ($body['email'] ?? ''),
                'callback' => (string) ($body['callback_url'] ?? ''),
                'paid_at' => null,
                'channel' => null,
            ];

            return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => [
                'authorization_url' => 'https://checkout.paystack.com/'.strtolower(substr(hash('sha1', $reference), 0, 15)),
                'access_code' => substr(hash('sha1', $reference), 0, 15),
                'reference' => $reference,
            ]]);
        }

        if ($request->method() === 'GET' && str_starts_with($path, '/transaction/verify/')) {
            $reference = rawurldecode(substr($path, strlen('/transaction/verify/')));
            $transaction = $this->transactions[$reference] ?? null;

            // A transaction of another Paystack account is unknown to this key.
            if ($transaction === null || $transaction['secret'] !== $secret) {
                return Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400);
            }

            return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                'id' => 4099260516,
                'domain' => $transaction['domain'],
                'status' => $transaction['status'],
                'reference' => $reference,
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'],
                'paid_at' => $transaction['paid_at'],
                'channel' => $transaction['channel'],
                'gateway_response' => $transaction['status'] === 'success' ? 'Successful' : 'The transaction was not completed',
                'customer' => ['email' => $transaction['email']],
            ]]);
        }

        return Http::response(['status' => false, 'message' => 'Not found'], 404);
    }
}

/** A fresh fake Paystack (no stray requests reach the network). */
function paystackServer(): FakePaystackServer
{
    $server = new FakePaystackServer;
    Http::swap(new Factory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/*' => fn (Request $request) => $server->handle($request)]);

    return $server;
}

/** Connects the organization's own Paystack through the writer (the owner's action); the fake server knows the key. */
function connectPaystack(FakePaystackServer $server, Organization $organization, User $owner, ?array $keys = null): PaystackConnection
{
    [$public, $secret] = $keys ?? paystackKeysOf($organization->slug);
    $server->know($secret, str_starts_with($secret, 'sk_test_') ? 'test' : 'live');

    return inOrganization($organization, fn (): PaystackConnection => app(PaystackConnections::class)->connect($owner, $public, $secret));
}

/** The organization's connection as stored now (whatever its state). */
function paystackConnectionOf(Organization $organization): ?PaystackConnection
{
    return inOrganization($organization, fn (): ?PaystackConnection => PaystackConnection::query()->first());
}

/** The organization's webhook address. */
function webhookPathOf(Organization $organization): string
{
    return '/webhooks/paystack/'.paystackConnectionOf($organization)?->webhook_token;
}

/** Starts paying the bill online through the service (as the pay-online form does) and returns the transaction. */
function startOnlinePayment(Organization $organization, Bill $bill, string $email = 'payer@example.com'): PaystackTransaction
{
    return inOrganization($organization, function () use ($bill, $email): PaystackTransaction {
        $balance = app(BillLedger::class)->balance($bill->id);
        app(PaystackTransactions::class)->start($balance, $email, 'https://app.test/return', 'https://app.test/pay');

        return PaystackTransaction::query()->latest('id')->firstOrFail();
    });
}

/** The organization's online payments, oldest first. */
function onlinePaymentsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => PaystackTransaction::query()->orderBy('id')->get());
}

/** The transaction as stored now. */
function storedTransaction(Organization $organization, PaystackTransaction $transaction): PaystackTransaction
{
    return inOrganization($organization, fn (): PaystackTransaction => PaystackTransaction::query()->findOrFail($transaction->id));
}

/** The organization's webhook deliveries, oldest first. */
function webhookLogOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => PaystackWebhookLog::query()->orderBy('id')->get());
}

/** Paystack's `charge.success` event for a transaction. */
function chargeSuccess(string $reference, int $kobo, array $data = []): array
{
    return [
        'event' => 'charge.success',
        'data' => $data + [
            'id' => 4099260516,
            'domain' => 'live',
            'status' => 'success',
            'reference' => $reference,
            'amount' => $kobo,
            'currency' => 'NGN',
            'paid_at' => '2026-12-25T09:30:00.000Z',
            'channel' => 'card',
            'customer' => ['email' => 'payer@example.com'],
        ],
    ];
}

/**
 * POSTs a webhook as Paystack does: the raw JSON body, signed with
 * HMAC-SHA512 of exactly those bytes with $secret (null: no signature).
 */
function paystackWebhook(string $path, array|string $payload, ?string $secret, array $server = []): TestResponse
{
    $raw = is_string($payload) ? $payload : (string) json_encode($payload);
    $server += ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_PAYSTACK_SIGNATURE'] = hash_hmac('sha512', $raw, $secret);
    }

    freshRequest();
    // Paystack is no signed-in browser.
    app('auth')->forgetGuards();

    return test()->call('POST', $path, [], [], [], $server, $raw);
}

/** The signed "pay online" address of a bill (as its pay page links it). */
function payOnlineLink(Organization $organization, Bill $bill): string
{
    return PaystackOption::link($bill->payment_reference, $organization->id);
}

/** A page of the pay-online flow, as a browser with no account. */
function payOnlinePage(string $url): TestResponse
{
    freshRequest();
    app('auth')->forgetGuards();

    return test()->get($url);
}

/**
 * Two agencies, each with a bank account, a tenant on a yearly lease and
 * its first bill (issued by the scheduled job on 25 Dec 2026, due 1 Jan
 * 2027): Adeyemi Realty (acme-homes, Kemi Adebayo, ₦850,000 — within the
 * standard online cap) and Bello Homes (other-realty, ₦700,000). A fake
 * Paystack ($test->paystack) refuses stray requests; receipt and invoice
 * PDFs and notifications are held back.
 */
function onlinePaymentsWorld(object $test): void
{
    holdReceiptPdfs();
    Notification::fake();
    seedOrganizationRoles();
    $test->travelTo('2026-12-25 09:00:00');
    $test->paystack = paystackServer();
    // No GoProperty key: bank accounts are saved "name not checked", no lookup is sent.
    config(['services.paystack.secret_key' => null]);

    $test->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $test->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $test->owner = joinAs(User::factory()->create(['name' => 'Ade Adeyemi', 'email' => 'ade@adeyemirealty.ng']), $test->acme);
    $test->otherOwner = joinAs(User::factory()->create(['name' => 'Bola Bello', 'email' => 'bola@bellohomes.ng']), $test->other);
    invoiceBranding($test->acme, $test->owner, ['tradingName' => 'Adeyemi Realty']);
    addBankAccount($test->acme, $test->owner, '0123456789', 'Adeyemi Realty Ltd');
    addBankAccount($test->other, $test->otherOwner, '0987654321', 'Bello Homes Ltd');

    $palm = buildingIn($test->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos']);
    $test->kemi = tenantIn($test->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    activeLease($test->acme, $test->owner, unitIn($test->acme, $palm, ['unit_label' => 'Flat 3B']), $test->kemi, ['rent' => '850,000']);
    $foreign = tenantIn($test->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']);
    activeLease($test->other, $test->otherOwner, unitIn($test->other, buildingIn($test->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court'])), $foreign, ['rent' => '700,000']);
    runBillGeneration();

    $test->bill = billsOf($test->acme)->sole();
    $test->foreign = billsOf($test->other)->sole();
}

/** The Paystack settings page and its Livewire snapshot. */
function paystackSettingsSnapshot(User $user, string $slug = 'acme-homes'): string
{
    freshRequest();
    $snapshot = LivewireHttp::snapshot(test()->actingAs($user)->get("/o/{$slug}/settings/paystack")->assertOk());
    freshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on the settings page, as a fresh request by the same user. */
function paystackSettingsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    freshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}
