<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * GoProperty's OWN Paystack account (`services.paystack.secret_key`;
 * BILLING_PAYMENTS.md § 4): its income only, here SMS credit bundles
 * (P05-03), later the subscription (P06-03). Never an organization's key
 * and never tenant money (AGENT.md § 3 rule 8; organizations' own accounts
 * are Modules\OnlinePayments'). The key is never logged.
 *
 * Available only with a key, and outside production only with a test key
 * (`sk_test_…`, § 4.3 rule 4): staging and local never take real money.
 */
final readonly class PlatformPaystack
{
    public function available(): bool
    {
        $key = $this->secretKey();

        return $key !== '' && (app()->environment('production') || str_starts_with($key, 'sk_test_'));
    }

    /** The mode Paystack must report a charge in: live only with a live key. */
    public function mode(): string
    {
        return str_starts_with($this->secretKey(), 'sk_live_') ? 'live' : 'test';
    }

    /** Checks a webhook's X-Paystack-Signature: HMAC-SHA512 of the raw body with the secret key, in constant time. */
    public function signedByPaystack(string $rawBody, ?string $signature): bool
    {
        $key = $this->secretKey();
        $signature = strtolower(trim((string) $signature));

        return $key !== '' && $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $key), $signature);
    }

    /**
     * Starts a checkout (POST /transaction/initialize) and returns the
     * address the payer is sent to.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PaystackUnavailable
     */
    public function initialize(array $fields): string
    {
        try {
            $response = $this->request()->post('/transaction/initialize', $fields);
        } catch (ConnectionException $exception) {
            throw new PaystackUnavailable('Paystack could not be reached.', previous: $exception);
        }

        $url = $response->json('data.authorization_url');

        if (! $response->successful() || $response->json('status') !== true || ! is_string($url) || ! self::checkoutAddress($url)) {
            throw new PaystackUnavailable('Paystack answered '.$response->status().'.');
        }

        return $url;
    }

    /**
     * What happened to a transaction (GET /transaction/verify/{reference});
     * null when Paystack has none with this reference.
     *
     * @throws PaystackUnavailable
     */
    public function verify(string $reference): ?VerifiedCharge
    {
        try {
            $response = $this->request()->get('/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException $exception) {
            throw new PaystackUnavailable('Paystack could not be reached.', previous: $exception);
        }

        if ($response->status() === 404 || ($response->status() === 400 && $response->json('status') === false)) {
            return null;
        }

        $data = [
            'reference' => $response->json('data.reference'),
            'status' => $response->json('data.status'),
            'amount' => $response->json('data.amount'),
            'currency' => $response->json('data.currency'),
            'domain' => $response->json('data.domain'),
        ];

        if (! $response->successful() || $response->json('status') !== true || ! is_string($data['reference']) || ! is_string($data['status'])
            || ! is_int($data['amount']) || ! is_string($data['currency']) || ! is_string($data['domain'])) {
            throw new PaystackUnavailable('Paystack answered '.$response->status().' with a transaction GoProperty cannot read.');
        }

        $channel = $response->json('data.channel');

        return new VerifiedCharge(
            reference: $data['reference'],
            status: strtolower($data['status']),
            amount: $data['amount'],
            currency: strtoupper($data['currency']),
            domain: strtolower($data['domain']),
            channel: is_string($channel) ? mb_substr($channel, 0, 30) : null,
        );
    }

    private function secretKey(): string
    {
        $key = config('services.paystack.secret_key');

        return is_string($key) ? trim($key) : '';
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
        $timeout = config('services.paystack.timeout', 8);

        return Http::baseUrl(is_string($baseUrl) ? $baseUrl : 'https://api.paystack.co')
            ->withToken($this->secretKey())
            ->acceptJson()
            ->asJson()
            ->timeout(is_numeric($timeout) ? max(1, (int) $timeout) : 8);
    }

    /** Only Paystack's own checkout pages: the payer is never sent anywhere else. */
    private static function checkoutAddress(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, ['checkout.paystack.com', 'paystack.com', 'standard.paystack.co'], true);
    }
}
