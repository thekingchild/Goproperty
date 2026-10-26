<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Staff\StaffLifecycle;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the staff lifecycle on every console request, Livewire updates
 * included (PLATFORM_CONSOLE.md § 1):
 *
 * - a deactivated account's session ends at its next request, whichever
 *   browser it is in ("deactivation immediately ends all sessions");
 * - an invitee who has set a password and has just enrolled 2FA becomes
 *   active.
 */
final class StaffAccountState
{
    public function __construct(private readonly StaffLifecycle $lifecycle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $staff = Filament::auth()->user();

        if (! $staff instanceof PlatformStaff) {
            return $next($request);
        }

        if ($staff->status === StaffStatus::Deactivated) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(Filament::getLoginUrl());
        }

        if ($staff->hasAcceptedInvitation()) {
            $this->lifecycle->activateIfReady($staff);
        }

        return $next($request);
    }
}
