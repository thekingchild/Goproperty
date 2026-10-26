<?php

declare(strict_types=1);

namespace App\Http\Controllers\Impersonation;

use App\Http\Middleware\ResumeImpersonation;
use App\Platform\Impersonation\ImpersonationEndReason;
use App\Platform\Impersonation\Impersonations;
use App\Tenancy\CurrentImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "End session" on the support banner (PLATFORM_CONSOLE.md § 6): ends the
 * support session (`manual`), wipes the customer-side session and returns
 * to the console.
 */
final class EndImpersonationController
{
    public function __invoke(Request $request, Impersonations $impersonations, CurrentImpersonation $current): RedirectResponse
    {
        $session = $current->session();

        if ($session !== null) {
            $impersonations->end($session, ImpersonationEndReason::Manual);
        }

        Auth::guard('web')->forgetUser();
        $current->clear();
        $request->session()->invalidate();

        return new RedirectResponse($session !== null ? ResumeImpersonation::consoleUrl($session) : url('/'));
    }
}
