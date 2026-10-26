<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Audit\AccessLog;
use App\Auth\SignIn\Turnstile;
use App\Onboarding\RegisterAccount;
use App\Onboarding\SignupBotCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Signup, step 1 (ONBOARDING_COMPLIANCE.md § 2): replaces Fortify's
 * register route. Every valid form ends on the same "check your email"
 * page whether or not the address already has an account (no account
 * enumeration), and nobody is signed in yet: the verification link opened
 * in this browser signs the new account in (VerifyEmailController).
 *
 * The bot check follows the Sign-in security settings (SignupBotCheck), and
 * the route is rate-limited per IP and per email address (`register`).
 */
final class RegisterController
{
    /** Session: the address the last signup in this browser used, for the "check your email" page. */
    public const string SESSION_EMAIL = 'signup.email';

    /** Session: the account this browser just created; its verification link signs it in. */
    public const string SESSION_USER = 'signup.user_id';

    public const string CHECK_FAILED = 'Please complete the security check and try again.';

    public function store(Request $request, RegisterAccount $register, SignupBotCheck $check, Turnstile $turnstile, AccessLog $log): RedirectResponse
    {
        $required = $check->required($request->ip());
        $check->count($request->ip());

        if ($required && ! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            $log->record('turnstile_failed', 'user', null, Str::lower(trim((string) $request->input('email'))));

            return back()->withInput($request->except('password'))->withErrors(['turnstile' => self::CHECK_FAILED]);
        }

        $user = $register($request->only('name', 'email', 'phone', 'password'));

        $request->session()->put(self::SESSION_EMAIL, Str::lower(trim((string) $request->input('email'))));

        if ($user !== null) {
            $request->session()->put(self::SESSION_USER, $user->id);
        } else {
            $request->session()->forget(self::SESSION_USER);
        }

        return redirect()->route('register.sent');
    }

    /** The same page for every signup. */
    public function sent(Request $request): View
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        return view('pages.auth.register-sent', ['email' => is_string($email) ? $email : null]);
    }

    /** The account step's form (Fortify's register view). */
    public static function formView(Request $request): View
    {
        return view('pages.auth.register', [
            'showCheck' => app(SignupBotCheck::class)->required($request->ip()),
        ]);
    }
}
