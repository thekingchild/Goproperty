<?php

declare(strict_types=1);

namespace App\Auth\Devices;

use App\Models\User;
use App\Models\UserDevice;
use App\Settings\SettingsStore;
use Illuminate\Contracts\Cookie\QueueingFactory as Cookies;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Recognises the browser a customer signs in from (UX_DESIGN.md § 3.1,
 * § 3.3). The browser keeps a random token in an encrypted, HttpOnly
 * cookie; the database keeps its SHA-256 per user. Scoped: it remembers
 * a token issued during the request.
 */
final class KnownDevices
{
    public const string COOKIE = 'gp_device';

    /** The token outlives "remember me" so a returning browser is known. */
    private const int COOKIE_MINUTES = 400 * 24 * 60;

    /** A token issued during this request, before the browser has sent it back. */
    private ?string $issued = null;

    public function __construct(
        private readonly Cookies $cookies,
        private readonly SettingsStore $settings,
    ) {}

    public function current(Request $request, User $user): ?UserDevice
    {
        $token = $this->token($request);

        return $token === null ? null : UserDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /**
     * Records this sign-in. Returns the device and whether it is new for
     * this user.
     *
     * @return array{UserDevice, bool}
     */
    public function signedIn(Request $request, User $user): array
    {
        $device = $this->current($request, $user);
        $details = ['user_agent' => Str::limit((string) $request->userAgent(), 250, ''), 'ip_address' => $request->ip(), 'last_seen_at' => now()];

        if ($device !== null) {
            $device->update($details);

            return [$device, false];
        }

        // Reuse the browser's token for another account, or make one.
        $token = $this->token($request) ?? Str::random(64);
        $this->issued = $token;
        $this->cookies->queue($this->cookies->make(self::COOKIE, $token, self::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));

        return [UserDevice::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)] + $details), true];
    }

    /** "Don't ask again on this device" (auth.trust_device_days). */
    public function trustForTwoFactor(Request $request, User $user): void
    {
        $days = $this->settings->get('auth.trust_device_days');

        $this->current($request, $user)?->update(['two_factor_trusted_until' => now()->addDays(is_int($days) ? $days : 30)]);
    }

    public function skipsTwoFactor(Request $request, User $user): bool
    {
        $until = $this->current($request, $user)?->two_factor_trusted_until;

        return $until !== null && $until->isFuture();
    }

    private function token(Request $request): ?string
    {
        $token = $this->issued ?? $request->cookie(self::COOKIE);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token) === 1 ? $token : null;
    }
}
