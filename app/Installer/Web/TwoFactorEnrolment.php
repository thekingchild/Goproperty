<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Models\PlatformStaff;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Config\Repository as Config;
use PragmaRX\Google2FAQRCode\Google2FA;
use SensitiveParameter;

/**
 * The first super-admin's authenticator app, set up inside the wizard
 * (INSTALLER.md § 5, step 6). Stored exactly as the console's own enrolment
 * stores it (Filament's app authentication: the secret encrypted, 8
 * recovery codes hashed), so the first console sign-in asks for the code.
 */
final class TwoFactorEnrolment
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly Config $config,
    ) {}

    public function newSecret(): string
    {
        return $this->provider()->generateSecret();
    }

    /** The QR code as a data: URI (SVG without imagick). */
    public function qrCode(PlatformStaff $staff, #[SensitiveParameter] string $secret): string
    {
        $qr = $this->google2fa->getQRCodeInline($this->issuer(), $staff->getAppAuthenticationHolderName(), $secret);

        return str_starts_with($qr, 'data:') ? $qr : 'data:image/svg+xml;base64,'.base64_encode($qr);
    }

    /** The otpauth:// address behind the QR code, for apps that take a link. */
    public function uri(PlatformStaff $staff, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($this->issuer(), $staff->getAppAuthenticationHolderName(), $secret);
    }

    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        $code = (string) preg_replace('/\s+/', '', $code);

        return preg_match('/^\d{6}$/D', $code) === 1
            && $this->google2fa->verifyKey($secret, $code, $this->provider()->getCodeWindow()) !== false;
    }

    /**
     * Saves the confirmed secret and fresh recovery codes (hashed); returns
     * the codes in plain text to show once.
     *
     * @return list<string>
     */
    public function confirm(PlatformStaff $staff, #[SensitiveParameter] string $secret): array
    {
        $provider = $this->provider();
        $codes = array_values($provider->generateRecoveryCodes());

        $provider->saveSecret($staff, $secret);
        $provider->saveRecoveryCodes($staff, $codes);

        return $codes;
    }

    /** As the console names it (the panel's brand + " console"). */
    private function issuer(): string
    {
        $name = $this->config->get('app.name');

        return (is_string($name) && $name !== '' ? $name : 'GoProperty').' console';
    }

    private function provider(): AppAuthentication
    {
        return AppAuthentication::make()->recoverable();
    }
}
