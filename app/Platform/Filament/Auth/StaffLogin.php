<?php

declare(strict_types=1);

namespace App\Platform\Filament\Auth;

use Filament\Auth\Pages\Login;
use Filament\Schemas\Schema;

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
