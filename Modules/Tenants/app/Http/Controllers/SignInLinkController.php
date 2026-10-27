<?php

declare(strict_types=1);

namespace Modules\Tenants\Http\Controllers;

use App\Members\InvitationForAnotherAccount;
use App\Members\InvitationUnavailable;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Portal\SignInLinks;

/**
 * An agency's one-time sign-in link, /portal/sign-in/{token}
 * (ONBOARDING_COMPLIANCE.md § 2; P03 decision 4; rules in
 * Portal\SignInLinks). Opening it (GET) changes nothing; "Sign in" (POST)
 * uses it up, sets the new password if one was chosen and signs the tenant
 * in. Someone signed in with another account is refused (403); unknown,
 * used, revoked and expired links get one "no longer works" page (404).
 * Never while GoProperty staff impersonate. Rate-limited per IP.
 */
#[BlockedWhileImpersonating]
final class SignInLinkController
{
    public function show(Request $request, string $token, SignInLinks $links): Response
    {
        $opened = $links->find($token);

        if ($opened === null) {
            return PortalInvitationController::unavailable('sign-in');
        }

        $user = $request->user();

        if ($user instanceof User && $user->id !== $opened->invitation->user_id) {
            return PortalInvitationController::wrongAccount($opened->organization, 'sign-in');
        }

        return response()->view('tenants::portal.sign-in-link', [
            'tenant' => $opened->tenant,
            'organization' => $opened->organization,
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token, SignInLinks $links): RedirectResponse|Response
    {
        $signedIn = $request->user();

        try {
            $account = $links->use($token, $signedIn instanceof User ? $signedIn : null, $request->only('password'));
        } catch (InvitationUnavailable) {
            return PortalInvitationController::unavailable('sign-in');
        } catch (InvitationForAnotherAccount) {
            $opened = $links->find($token);

            return $opened !== null ? PortalInvitationController::wrongAccount($opened->organization, 'sign-in') : PortalInvitationController::unavailable('sign-in');
        }

        if (! $signedIn instanceof User) {
            Auth::guard('web')->login($account);
            $request->session()->regenerate();
        }

        return redirect()->route('portal.home')->with('status', __('You are signed in.'));
    }
}
