<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Auth\Devices\KnownDevices;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Fortify's 2FA step, except on a browser the customer trusted with
 * "Don't ask again on this device" (UX_DESIGN.md § 3.3).
 */
final class RedirectIfTwoFactorNeeded extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && app(KnownDevices::class)->skipsTwoFactor($request, $user)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
