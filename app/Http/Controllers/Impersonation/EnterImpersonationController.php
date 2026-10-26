<?php

declare(strict_types=1);

namespace App\Http\Controllers\Impersonation;

use App\Models\ImpersonationSession;
use App\Platform\Impersonation\ImpersonationRefused;
use App\Platform\Impersonation\Impersonations;
use App\Tenancy\CurrentImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hand-off from the console to the customer app (PLATFORM_CONSOLE.md
 * § 6): a one-time link, signed and valid 60 seconds, that opens a
 * separate customer-side session for the support session. Anything the
 * browser held on the customer side before (a customer signed in, a
 * "remember me" cookie) is dropped first. The session then holds only the
 * support session's ID: App\Http\Middleware\ResumeImpersonation acts as the
 * user request by request, until the support session ends.
 */
final class EnterImpersonationController
{
    public function __invoke(Request $request, Impersonations $impersonations, int $session): Response
    {
        try {
            $opened = $impersonations->enter($session, (string) $request->query('token', ''), $request->hasValidRelativeSignature());
        } catch (ImpersonationRefused $refused) {
            return response()->view('impersonation.refused', ['message' => $refused->getMessage()], 403);
        }

        $guard = Auth::guard('web');
        $guard->forgetUser();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        $request->session()->invalidate();
        $request->session()->put(CurrentImpersonation::SESSION_KEY, $opened->id);

        return new RedirectResponse($this->landing($opened));
    }

    /** The organization's workspace when the user may open it, else their organization picker. */
    private function landing(ImpersonationSession $session): string
    {
        return in_array($session->organization_id, $session->user->workspaceOrganizationIds(), true)
            ? route('workspace.home', ['organization' => $session->organization->slug])
            : route('dashboard');
    }
}
