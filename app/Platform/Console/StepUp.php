<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Models\PlatformStaff;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Step-up for sensitive console settings (PLATFORM_CONSOLE.md § 9.1): the
 * staff member re-enters their password, and their 2FA code once staff 2FA
 * is enrolled (P00-10), and may then change such settings for 10 minutes.
 */
final readonly class StepUp
{
    public const int MINUTES = 10;

    private const string SESSION_KEY = 'console.step_up_at';

    /** Wrong answers allowed per staff member per minute. */
    private const int ATTEMPTS = 5;

    public function __construct(
        private Session $session,
        private Hasher $hasher,
        private TwoFactorAuthenticationProvider $twoFactor,
    ) {}

    public function isFresh(): bool
    {
        $at = $this->session->get(self::SESSION_KEY);

        return is_int($at) && $at >= now()->subMinutes(self::MINUTES)->getTimestamp();
    }

    public function needsCode(PlatformStaff $staff): bool
    {
        return $staff->two_factor_confirmed_at !== null && $staff->two_factor_secret !== null;
    }

    /**
     * Checks the answers and starts the 10-minute window. Returns the
     * problem in words, or null when confirmed.
     */
    public function confirm(PlatformStaff $staff, string $password, ?string $code = null): ?string
    {
        $limiter = 'console-step-up:'.$staff->id;

        if (RateLimiter::tooManyAttempts($limiter, self::ATTEMPTS)) {
            return 'Too many attempts. Try again in '.RateLimiter::availableIn($limiter).' seconds.';
        }

        // The password first, so a mistyped one does not use up a valid code.
        $passwordOk = $this->hasher->check($password, $staff->password);
        $codeOk = $passwordOk && (! $this->needsCode($staff) || ($code !== null && $this->twoFactor->verify((string) $staff->two_factor_secret, $code)));

        if (! $codeOk) {
            RateLimiter::hit($limiter);

            return $this->needsCode($staff) ? 'The password or code is not right.' : 'The password is not right.';
        }

        RateLimiter::clear($limiter);
        $this->session->put(self::SESSION_KEY, now()->getTimestamp());

        return null;
    }
}
