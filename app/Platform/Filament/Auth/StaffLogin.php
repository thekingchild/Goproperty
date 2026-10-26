<?php

declare(strict_types=1);

namespace App\Platform\Filament\Auth;

use App\Audit\AccessLog;
use App\Models\PlatformStaff;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Staff sign-in (UX_DESIGN.md § 3.2): a single centred card on a dark
 * background, "Staff console", no sign-up link and no marketing. Email and
 * password, then the mandatory 2FA code. Filament's flow keeps one generic
 * error, equal timing and a per-IP rate limit.
 */
final class StaffLogin extends Login
{
    /**
     * No "Remember me": staff must use 2FA on every sign-in, and remembering
     * is not offered to such accounts (PLATFORM_CONSOLE.md § 9.6).
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    /**
     * Records the 2FA step's result for the authentication log
     * (PLATFORM_CONSOLE.md § 8); the password step is logged from the
     * guard's Login and Failed events.
     */
    public function authenticate(): ?LoginResponse
    {
        $challenged = filled($this->userUndertakingMultiFactorAuthentication) ? $this->getUserUndertakingMultiFactorAuthentication() : null;

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            if ($challenged instanceof PlatformStaff) {
                app(AccessLog::class)->record('2fa_failed', $challenged);
            }

            throw $exception;
        }

        if ($challenged instanceof PlatformStaff && Filament::auth()->check()) {
            app(AccessLog::class)->record('2fa_passed', $challenged);
        }

        return $response;
    }

    public function getHeading(): string
    {
        return filled($this->userUndertakingMultiFactorAuthentication) ? 'Enter your 2FA code' : 'Staff console';
    }

    public function getSubheading(): string
    {
        return filled($this->userUndertakingMultiFactorAuthentication)
            ? 'Open your authenticator app and enter the 6-digit code.'
            : 'Sign in with your work email. Two-factor authentication is required.';
    }
}
