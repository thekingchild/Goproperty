<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Auth\SignIn\Turnstile;
use App\Settings\SettingsStore;
use Illuminate\Cache\RateLimiter;

/**
 * Turnstile on the signup form, by the Sign-in security settings
 * (PLATFORM_CONSOLE.md § 9.6), when keys are set:
 *
 * - `always`: every signup;
 * - `after_failures`: from the 4th signup from one IP address within 15
 *   minutes. Signup has no wrong answer to count (the reply is the same
 *   for every valid form), so every attempt counts.
 */
final readonly class SignupBotCheck
{
    public const int FREE_ATTEMPTS = 3;

    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private RateLimiter $limiter,
        private SettingsStore $settings,
        private Turnstile $turnstile,
    ) {}

    public function required(?string $ip): bool
    {
        if (! $this->turnstile->configured()) {
            return false;
        }

        return $this->settings->get('auth.turnstile_mode') === 'always'
            || $this->limiter->attempts($this->key($ip)) >= self::FREE_ATTEMPTS;
    }

    public function count(?string $ip): void
    {
        $this->limiter->hit($this->key($ip), self::WINDOW_SECONDS);
    }

    private function key(?string $ip): string
    {
        return 'signup-check:'.hash('sha256', (string) $ip);
    }
}
