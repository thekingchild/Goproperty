<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\SmsCreditBalance;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../tests/Support/organization-settings.php';

/*
 * Helpers for the SMS credit purchase tests (P05-03): GoProperty's own
 * Paystack account, faked (no test reaches the network). The first bundle
 * bought in a test is invoice PR-2026-000001, reference
 * PINV-PR-2026-000001-a1; 500 credits at ₦6 are ₦3,000 + ₦225 VAT.
 */

const FIRST_SMS_REFERENCE = 'PINV-PR-2026-000001-a1';

const BUNDLE_500_KOBO = 322_500;

/**
 * Paystack starts every checkout, and answers verify with this
 * transaction (or 404 when null).
 *
 * @param  array<string, mixed>|null  $transaction
 */
function platformPaystack(?array $transaction = null): Factory
{
    withPaystackKey();

    return fakePaystack([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => 'https://checkout.paystack.com/sms500', 'access_code' => 'sms500', 'reference' => FIRST_SMS_REFERENCE]]),
        'api.paystack.co/transaction/verify/*' => $transaction === null
            ? Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)
            : Http::response(['status' => true, 'message' => 'Verification successful', 'data' => $transaction + ['reference' => FIRST_SMS_REFERENCE, 'status' => 'success', 'amount' => BUNDLE_500_KOBO, 'currency' => 'NGN', 'domain' => 'test', 'channel' => 'card', 'paid_at' => '2026-11-30T09:05:00.000Z']]),
    ]);
}

/** Presses "Buy" on Settings → SMS credits; returns the Livewire reply. */
function buyCredits(User $user, int $credits, string $slug = 'acme-homes'): TestResponse
{
    return LivewireHttp::update(test(), settingsPage($user, 'sms-credits', $slug), [], [['buy', [$credits]]]);
}

/** Paystack's webhook to GoProperty's own account, signed with its key unless told otherwise. */
function platformWebhook(string $event = 'charge.success', string $reference = FIRST_SMS_REFERENCE, ?string $signature = null): TestResponse
{
    $body = json_encode(['event' => $event, 'data' => ['reference' => $reference, 'amount' => BUNDLE_500_KOBO, 'currency' => 'NGN', 'status' => 'success']], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, 'sk_test_goproperty_platform'),
    ], $body);
}

function purchasedCredits(Organization $organization): int
{
    return (int) inOrganization($organization, fn () => SmsCreditBalance::query()->value('purchased_credits'));
}
