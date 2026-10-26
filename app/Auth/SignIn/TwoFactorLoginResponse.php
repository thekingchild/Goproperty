<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Auth\Devices\KnownDevices;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as Contract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a passed 2FA challenge: remembers the browser when "Don't ask
 * again on this device" was ticked (auth.trust_device_days).
 */
final readonly class TwoFactorLoginResponse implements Contract
{
    public function __construct(private KnownDevices $devices) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->boolean('trust_device')) {
            $this->devices->trustForTwoFactor($request, $user);
        }

        return $request->wantsJson() ? response()->noContent() : redirect()->intended(Fortify::redirects('login'));
    }
}
