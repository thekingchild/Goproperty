<?php

declare(strict_types=1);

namespace App\Audit;

use App\Auth\Devices\KnownDevices;
use App\Auth\SignIn\Identifier;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Notifications\NewDeviceSignIn;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Turns Laravel, Fortify and Filament sign-in events into the
 * authentication log for both guards (PLATFORM_CONSOLE.md § 8). A customer
 * sign-in also records the browser and, when it is new to an account that
 * has signed in before, emails the account (UX_DESIGN.md § 3.1).
 */
final class AuthenticationEvents
{
    public function login(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof PlatformStaff) {
            app(AccessLog::class)->record('login_success', $user);

            return;
        }

        if (! $user instanceof User) {
            return;
        }

        [$device, $new] = app(KnownDevices::class)->signedIn(request(), $user);
        $known = $user->devices()->count() > 1;

        app(AccessLog::class)->record(request()->routeIs('passkey.login') ? 'passkey_login' : 'login_success', $user, newDevice: $new);

        if ($new && $known && $user->email !== null) {
            $user->notify(new NewDeviceSignIn($device));
        }
    }

    public function failed(Failed $event): void
    {
        $log = app(AccessLog::class);

        if ($event->guard === 'staff') {
            $email = $event->credentials['email'] ?? null;
            $id = $event->user instanceof PlatformStaff ? $event->user->id
                : (is_string($email) ? PlatformStaff::query()->where('email', strtolower(trim($email)))->value('id') : null);

            $log->record('login_failed', 'platform_staff', is_int($id) ? $id : null, is_string($email) ? $email : null);

            return;
        }

        $identifier = Identifier::from($event->credentials['identifier'] ?? request()->input('identifier'));
        $id = $event->user instanceof User ? $event->user->id : match (true) {
            $identifier->email !== null => User::query()->where('email', $identifier->email)->value('id'),
            $identifier->phone !== null => User::query()->where('phone', $identifier->phone)->value('id'),
            default => null,
        };

        $log->record('login_failed', 'user', is_int($id) ? $id : null, $identifier->value);
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User || $event->user instanceof PlatformStaff) {
            app(AccessLog::class)->record('logout', $event->user);
        }
    }

    public function passwordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            app(AccessLog::class)->record('password_reset', $event->user);
        }
    }

    public function twoFactorPassed(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        // Fortify's challenge only ever holds a customer (App\Models\User).
        app(AccessLog::class)->record('2fa_passed', $event->user);
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        // Fortify's challenge only ever holds a customer (App\Models\User).
        app(AccessLog::class)->record('2fa_failed', $event->user);
    }

    public function recoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        if ($event->user instanceof User) {
            app(AccessLog::class)->record('recovery_code_used', $event->user);
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Failed::class => 'failed',
            Logout::class => 'logout',
            PasswordReset::class => 'passwordReset',
            ValidTwoFactorAuthenticationCodeProvided::class => 'twoFactorPassed',
            TwoFactorAuthenticationFailed::class => 'twoFactorFailed',
            RecoveryCodeReplaced::class => 'recoveryCodeUsed',
        ];
    }
}
