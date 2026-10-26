<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Settings\SettingsStore;
use Illuminate\Cache\RateLimiter;

/**
 * Failed sign-in bookkeeping (UX_DESIGN.md § 3.1, PLATFORM_CONSOLE.md § 9.6):
 *
 * - the bot check shows after 3 failures for the account or the IP within
 *   15 minutes (or always, by setting), when Turnstile keys are set;
 * - sign-in pauses for auth.lockout_minutes after auth.lockout_after
 *   failures for the same identifier from the same IP; without Turnstile
 *   keys the pause starts at the bot-check threshold instead.
 */
final readonly class SignInSecurity
{
    public const int CHECK_AFTER = 3;

    public const int CHECK_WINDOW_SECONDS = 900;

    public function __construct(
        private RateLimiter $limiter,
        private SettingsStore $settings,
        private Turnstile $turnstile,
    ) {}

    public function recordFailure(Identifier $identifier, ?string $ip): void
    {
        $this->limiter->hit($this->pairKey($identifier, $ip), $this->lockoutMinutes() * 60);
        $this->limiter->hit($this->identifierKey($identifier), self::CHECK_WINDOW_SECONDS);
        $this->limiter->hit($this->ipKey($ip), self::CHECK_WINDOW_SECONDS);
    }

    /** A successful sign-in clears the account's count, not the IP's. */
    public function clear(Identifier $identifier, ?string $ip): void
    {
        $this->limiter->clear($this->pairKey($identifier, $ip));
        $this->limiter->clear($this->identifierKey($identifier));
    }

    public function isLocked(Identifier $identifier, ?string $ip): bool
    {
        return $this->limiter->tooManyAttempts($this->pairKey($identifier, $ip), $this->lockoutThreshold());
    }

    /** Seconds until a paused identifier + IP may try again. */
    public function lockedFor(Identifier $identifier, ?string $ip): int
    {
        return $this->limiter->availableIn($this->pairKey($identifier, $ip));
    }

    public function lockoutThreshold(): int
    {
        $after = $this->settings->get('auth.lockout_after');
        $after = is_int($after) ? $after : 5;

        return $this->turnstile->configured() ? $after : min($after, self::CHECK_AFTER);
    }

    public function lockoutMinutes(): int
    {
        $minutes = $this->settings->get('auth.lockout_minutes');

        return is_int($minutes) ? $minutes : 15;
    }

    /** Must this attempt pass the bot check? */
    public function needsCheck(?Identifier $identifier, ?string $ip): bool
    {
        if (! $this->turnstile->configured()) {
            return false;
        }

        if ($this->settings->get('auth.turnstile_mode') === 'always') {
            return true;
        }

        return $this->limiter->attempts($this->ipKey($ip)) >= self::CHECK_AFTER
            || ($identifier !== null && $this->limiter->attempts($this->identifierKey($identifier)) >= self::CHECK_AFTER);
    }

    private function pairKey(Identifier $identifier, ?string $ip): string
    {
        return 'sign-in:pair:'.hash('sha256', $identifier->value.'|'.$ip);
    }

    private function identifierKey(Identifier $identifier): string
    {
        return 'sign-in:identifier:'.$identifier->key();
    }

    private function ipKey(?string $ip): string
    {
        return 'sign-in:ip:'.hash('sha256', (string) $ip);
    }
}
