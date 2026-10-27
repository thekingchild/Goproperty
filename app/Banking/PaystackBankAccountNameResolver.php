<?php

declare(strict_types=1);

namespace App\Banking;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Paystack's account-name lookup (GET /bank/resolve), with GoProperty's own
 * secret key (config/services.php `paystack.secret_key`). The lookup moves
 * no money, so using the platform's key does not route tenant money through
 * GoProperty (ONBOARDING_COMPLIANCE.md § 0); an organization's own Paystack
 * keys (§ 4.2) are optional and arrive later, while every organization
 * needs its bank account checked. No key: Unavailable, nothing is sent.
 */
final readonly class PaystackBankAccountNameResolver implements BankAccountNameResolver
{
    public function __construct(private AccountNameMatcher $matcher) {}

    public function check(string $bankCode, string $accountNumber, string $expectedName): NameCheckResult
    {
        $key = config('services.paystack.secret_key');

        if (! is_string($key) || $key === '') {
            return NameCheckResult::unavailable('No Paystack key is configured.');
        }

        $baseUrl = config('services.paystack.base_url', 'https://api.paystack.co');
        $timeout = config('services.paystack.timeout', 8);

        try {
            $response = Http::baseUrl(is_string($baseUrl) ? $baseUrl : 'https://api.paystack.co')
                ->withToken($key)
                ->acceptJson()
                ->timeout(is_numeric($timeout) ? max(1, (int) $timeout) : 8)
                ->get('/bank/resolve', ['account_number' => $accountNumber, 'bank_code' => $bankCode]);
        } catch (ConnectionException) {
            return NameCheckResult::unavailable('Paystack could not be reached.');
        }

        if ($response->successful() && $response->json('status') === true) {
            $name = $response->json('data.account_name');

            if (! is_string($name) || trim($name) === '') {
                return NameCheckResult::unavailable('Paystack returned no account name.');
            }

            $name = trim((string) preg_replace('/\s+/', ' ', $name));

            return $this->matcher->matches($expectedName, $name) ? NameCheckResult::matched($name) : NameCheckResult::mismatched($name);
        }

        // "Could not resolve account name": the bank has no such account.
        if ($response->status() === 422 && $response->json('status') === false) {
            return NameCheckResult::notFound();
        }

        return NameCheckResult::unavailable('Paystack answered '.$response->status().'.');
    }
}
