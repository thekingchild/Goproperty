<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Audit\AccessLog;
use App\Auth\SignIn\Turnstile;
use App\Marketing\PlanCatalogue;
use App\Marketing\SignupPlanChoice;
use App\Onboarding\RegisterAccount;
use App\Onboarding\SignupBotCheck;
use App\Privacy\Consents\Consents;
use App\Privacy\Consents\ConsentSource;
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
 * A new account's acceptance of the terms and privacy policy, and its
 * choice about product email, are consent records (P09-02).
 */
final class RegisterController
{
    /** Session: the address the last signup in this browser used, for the "check your email" page. */
    public const string SESSION_EMAIL = 'signup.email';

    /** Session: the account this browser just created; its verification link signs it in. */
    public const string SESSION_USER = 'signup.user_id';

    public const string CHECK_FAILED = 'Please complete the security check and try again.';

    public function store(Request $request, RegisterAccount $register, SignupBotCheck $check, Turnstile $turnstile, AccessLog $log, Consents $consents): RedirectResponse
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
            // The page says creating the account means agreeing to both (P09-02, NDPA consent at signup).
            $consents->acceptTerms($user, ConsentSource::Signup);

            if ($request->boolean('marketing_email')) {
                $consents->setMarketingEmail($user, true, ConsentSource::Signup);
            }

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

    /**
     * The account step's form (Fortify's register view). A pricing-page link
     * (`?plan=growth&cycle=annual`, P06-11) is remembered for the wizard's
     * plan step and shown above the form.
     */
    public static function formView(Request $request): View
    {
        $choice = app(SignupPlanChoice::class);
        $choice->remember($request);
        $plan = $choice->plan($request->session());

        return view('pages.auth.register', [
            'showCheck' => app(SignupBotCheck::class)->required($request->ip()),
            'chosenPlan' => $plan,
            'chosenCycle' => $choice->cycle($request->session()),
            'monthsPaidPerYear' => app(PlanCatalogue::class)->monthsPaidPerYear(),
        ]);
    }
}
