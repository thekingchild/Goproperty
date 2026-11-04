<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Forms that ask a signed-in person for their current password (change
 * password, delete account): at most 5 tries a minute per account, as
 * "Confirm your password" (docs/security/review-2026-11.md SR-06), so a
 * borrowed or stolen session cannot guess the password through them.
 */
final class PasswordGuesses
{
    public const int PER_MINUTE = 5;

    /** Counts one try; refuses it, on $field, once the minute's tries are used. */
    public static function attempt(Authenticatable $user, string $field): void
    {
        $key = 'current-password:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages([
                $field => __('Too many tries. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
