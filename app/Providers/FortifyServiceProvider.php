<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\Devices\KnownDevices;
use App\Auth\SignIn\FailedAttempt;
use App\Auth\SignIn\FindUser;
use App\Auth\SignIn\GuardSignIn;
use App\Auth\SignIn\RedirectIfTwoFactorNeeded;
use App\Auth\SignIn\TwoFactorLoginResponse;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(FailedAttempt::class);
        $this->app->scoped(KnownDevices::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
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
        Fortify::registerView(fn () => view('pages::auth.register'));
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
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('id:'.hash('sha256', Str::lower(trim((string) $request->input('identifier'))).'|'.$request->ip())),
        ]);

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
