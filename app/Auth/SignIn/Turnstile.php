<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Settings\SettingsStore;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Cloudflare Turnstile (PLATFORM_CONSOLE.md § 9.6): the token from the
 * widget is checked server-side with a 5-second timeout. Any doubt (no
 * token, no keys, network error, unexpected answer) fails closed.
 */
final readonly class Turnstile
{
    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const string SCRIPT_HOST = 'https://challenges.cloudflare.com';

    public function __construct(
        private SettingsStore $settings,
        private Http $http,
    ) {}

    public function configured(): bool
    {
        return $this->siteKey() !== null && $this->secretKey() !== null;
    }

    public function siteKey(): ?string
    {
        $key = $this->settings->get('auth.turnstile_site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function verify(mixed $token, ?string $ip): bool
    {
        $secret = $this->secretKey();

        if ($secret === null || ! is_string($token) || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = $this->http->asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }

    private function secretKey(): ?string
    {
        $key = $this->settings->get('auth.turnstile_secret_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
