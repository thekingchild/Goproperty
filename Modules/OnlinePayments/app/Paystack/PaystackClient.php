<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Paystack;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Paystack's API with the ORGANIZATION's own secret key
 * (ONBOARDING_COMPLIANCE.md § 4.2): never GoProperty's own key
 * (`services.paystack.secret_key`, used only for account-name lookups,
 * P01-05), because the money settles into the organization's account
 * (AGENT.md § 3 rule 8). The base address and timeout are the shared
 * `services.paystack` settings. Keys are never logged.
 */
final readonly class PaystackClient
{
    /** Tries the key with a harmless read (the newest transaction): Paystack answers 401 for a key it does not know. */
    public function test(string $secretKey): KeyTest
    {
        try {
            $response = $this->request($secretKey)->get('/transaction', ['perPage' => 1]);
        } catch (ConnectionException) {
            return KeyTest::Unavailable;
        }

        if ($response->status() === 401) {
            return KeyTest::Refused;
        }

        return $response->successful() && $response->json('status') === true ? KeyTest::Accepted : KeyTest::Unavailable;
    }

    /**
     * Starts a transaction (POST /transaction/initialize) and returns the
     * checkout address the payer is sent to.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws PaystackRefused when Paystack refuses it (4xx with a message)
     * @throws PaystackUnavailable when Paystack cannot be reached or answers oddly
     */
    public function initialize(string $secretKey, array $fields): string
    {
        try {
            $response = $this->request($secretKey)->post('/transaction/initialize', $fields);
        } catch (ConnectionException $exception) {
            throw new PaystackUnavailable('Paystack could not be reached.', previous: $exception);
        }

        if ($response->clientError()) {
            $message = $response->json('message');

            throw new PaystackRefused(is_string($message) && $message !== '' ? mb_substr($message, 0, 200) : 'Paystack refused the payment.');
        }

        $url = $response->json('data.authorization_url');

        if (! $response->successful() || $response->json('status') !== true || ! is_string($url) || ! self::checkoutAddress($url)) {
            throw new PaystackUnavailable('Paystack answered '.$response->status().'.');
        }

        return $url;
    }

    /**
     * Asks Paystack what happened to a transaction (GET
     * /transaction/verify/{reference}). Null when Paystack has no
     * transaction with this reference.
     *
     * @throws PaystackUnavailable when Paystack cannot be reached, refuses the key, or answers something unreadable
     */
    public function verify(string $secretKey, string $reference): ?VerifiedTransaction
    {
        try {
            $response = $this->request($secretKey)->get('/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException $exception) {
            throw new PaystackUnavailable('Paystack could not be reached.', previous: $exception);
        }

        if ($response->status() === 404 || ($response->status() === 400 && $response->json('status') === false)) {
            return null;
        }

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaystackUnavailable('Paystack answered '.$response->status().'.');
        }

        return self::transaction($response);
    }

    private function request(string $secretKey): PendingRequest
    {
        $baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
        $timeout = config('services.paystack.timeout', 8);

        return Http::baseUrl(is_string($baseUrl) ? $baseUrl : 'https://api.paystack.co')
            ->withToken($secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout(is_numeric($timeout) ? max(1, (int) $timeout) : 8);
    }

    /** @throws PaystackUnavailable */
    private static function transaction(Response $response): VerifiedTransaction
    {
        $reference = $response->json('data.reference');
        $status = $response->json('data.status');
        $amount = $response->json('data.amount');
        $currency = $response->json('data.currency');
        $domain = $response->json('data.domain');

        if (! is_string($reference) || ! is_string($status) || ! is_int($amount) || ! is_string($currency) || ! is_string($domain)) {
            throw new PaystackUnavailable('Paystack answered with a transaction GoProperty cannot read.');
        }

        $paidAt = $response->json('data.paid_at') ?? $response->json('data.paidAt');
        $channel = $response->json('data.channel');

        try {
            $paidAt = is_string($paidAt) && $paidAt !== '' ? CarbonImmutable::parse($paidAt) : null;
        } catch (Throwable) {
            $paidAt = null;
        }

        return new VerifiedTransaction(
            reference: $reference,
            status: strtolower($status),
            amount: $amount,
            currency: strtoupper($currency),
            domain: strtolower($domain),
            paidAt: $paidAt,
            channel: is_string($channel) ? mb_substr($channel, 0, 30) : null,
        );
    }

    /** Only Paystack's own checkout pages: the payer is never sent anywhere else. */
    private static function checkoutAddress(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, ['checkout.paystack.com', 'paystack.com', 'standard.paystack.co'], true);
    }
}
