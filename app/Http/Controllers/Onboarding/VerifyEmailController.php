<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * The email verification link (ONBOARDING_COMPLIANCE.md § 2: "verify email
 * by link"), replacing Fortify's route, which needs the user signed in.
 * Signup signs nobody in (RegisterController), so:
 *
 * - signed in as the link's account: the address is confirmed;
 * - signed out, in the browser that created this unverified account: the
 *   address is confirmed and the account signed in, so signup continues
 *   straight to the organization step. The link alone is not enough: the
 *   browser must also hold the signup session;
 * - anyone else signs in first and comes back to the link.
 *
 * A wrong hash, or a signed-in user opening someone else's link, gets 403.
 */
final class VerifyEmailController
{
    public function __invoke(Request $request, StatefulGuard $guard, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            abort(403);
        }

        $current = $request->user();
        $home = Fortify::redirects('email-verification').'?verified=1';

        if ($current !== null) {
            abort_unless($current instanceof User && $current->is($user), 403);
            $this->confirm($user);

            return redirect()->intended($home);
        }

        if (! $user->hasVerifiedEmail()
            && $user->two_factor_confirmed_at === null
            && (int) $request->session()->get(RegisterController::SESSION_USER) === $user->id) {
            $this->confirm($user);
            $guard->login($user);
            $request->session()->regenerate();
            $request->session()->forget([RegisterController::SESSION_USER, RegisterController::SESSION_EMAIL]);

            return redirect($home);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('login')->with('status', 'Sign in to confirm your email address.');
    }

    private function confirm(User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }
}
