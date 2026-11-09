<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\Devices\KnownDevices;
use App\Auth\SignIn\FailedAttempt;
use App\Auth\SignIn\FindUser;
use App\Auth\SignIn\GuardSignIn;
use App\Auth\SignIn\LoginResponse;
use App\Auth\SignIn\PasskeyLoginResponse;
use App\Auth\SignIn\RedirectIfTwoFactorNeeded;
use App\Auth\SignIn\TwoFactorLoginResponse;
use App\Http\Controllers\Onboarding\RegisterController;
use App\Http\Middleware\RequireFreshPassword;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Livewire\Livewire;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Sign-ins a minute from one IP address (a backstop: lockout and the bot
     * check do the per-account work). 120, not 30 (product owner,
     * 2026-11-09, P09-03): Nigerian mobile networks put many people behind
     * one shared address, and a busy morning must not turn tenants away.
     */
    public const int SIGN_INS_PER_ADDRESS = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(FailedAttempt::class);
        $this->app->scoped(KnownDevices::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);

        // Every way of signing in lands on the user's workspace
        // (ONBOARDING_COMPLIANCE.md § 1 "After login", WorkspaceLanding).
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);

        // As Laravel binds its own RequirePassword: the configured timeout.
        $this->app->bind(RequireFreshPassword::class, fn ($app): RequireFreshPassword => new RequireFreshPassword(
            $app[ResponseFactory::class],
            $app[UrlGenerator::class],
            $app['config']->get('auth.password_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // Settings → Security sits behind "Confirm your password"; its
        // Livewire calls (turn off 2FA, remove a passkey) need the same
        // fresh confirmation, not only the page load
        // (docs/security/review-2026-11.md SR-08).
        Livewire::addPersistentMiddleware([RequireFreshPassword::class]);
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // One field, email or phone number (UX_DESIGN.md § 3.1).
        Fortify::authenticateUsing(fn (Request $request) => app(FindUser::class)($request));

        // "Confirm your password" checks the signed-in account's password.
        // Fortify's own check looks the account up again by the login field,
        // which here is `identifier`, no column, so every confirmation
        // failed with a database error (docs/security/review-2026-11.md
        // SR-11).
        Fortify::confirmPasswordsUsing(static fn (mixed $user, ?string $password): bool => $user instanceof User
            && is_string($password) && $password !== ''
            && Hash::check($password, $user->getAuthPassword()));

        // Fortify reports wrong details (unknown account or wrong password)
        // with this event; GuardSignIn counts it towards lockout.
        Event::listen(Failed::class, function (Failed $event): void {
            if ($event->guard === config('fortify.guard')) {
                app(FailedAttempt::class)->failed = true;
            }
        });

        // Lockout, bot check and the generic error wrap Fortify's own steps.
        Fortify::authenticateThrough(fn (): array => array_values(array_filter([
            GuardSignIn::class,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectIfTwoFactorNeeded::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ])));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn (Request $request) => RegisterController::formView($request));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // A backstop on the login route; lockout and the bot check
        // (App\Auth\SignIn) do the per-account work.
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(self::SIGN_INS_PER_ADDRESS)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('id:'.hash('sha256', Str::lower(trim((string) $request->input('identifier'))).'|'.$request->ip())),
        ]);

        // Signup (RegisterController): per IP, and per email address so one
        // inbox cannot be flooded; the same limits whether or not the
        // address has an account.
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(30)->by('ip-hour:'.$request->ip()),
            Limit::perHour(6)->by('email:'.hash('sha256', Str::lower(trim((string) $request->input('email'))))),
        ]);

        // "Confirm your password" and the reset form (ThrottlePasswordChecks;
        // docs/security/review-2026-11.md SR-06): per account when signed
        // in, else per IP.
        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password-confirm:'.($request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip())));
        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(10)->by('password-reset:'.$request->ip()));

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
