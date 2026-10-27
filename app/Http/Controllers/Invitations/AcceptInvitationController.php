<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Members\InvitationAcceptance;
use App\Members\InvitationForAnotherAccount;
use App\Members\InvitationNeedsSignIn;
use App\Members\InvitationUnavailable;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The invitation link, /invitations/{token} (UX_DESIGN.md § 3.3 "Accept
 * invitation", ONBOARDING_COMPLIANCE.md § 2), on the sign-in pages' split
 * layout. Shows the organization, who invited and the roles, then:
 *
 * - signed in as the invited address: "Join" (POST) adds the account;
 * - signed in as anyone else: refused (the link is bound to its address);
 * - not signed in, the address has an account: "Sign in to accept", which
 *   comes back here after sign-in (intended URL);
 * - not signed in, no account: name, optional mobile number and password;
 *   the account is created, signed in and lands in the workspace.
 *
 * Unknown, used, revoked and expired links get the same "no longer valid"
 * page (404). Never available while GoProperty staff impersonate a user
 * (it signs in and changes members). Rate-limited per IP (`invitations`).
 */
#[BlockedWhileImpersonating]
final class AcceptInvitationController
{
    public function show(Request $request, string $token, InvitationAcceptance $acceptance): Response
    {
        $invitation = $acceptance->find($token);

        if ($invitation === null) {
            return $this->unavailable();
        }

        $user = $request->user();

        if ($user instanceof User && ! $acceptance->isFor($invitation, $user)) {
            return $this->wrongAccount($invitation);
        }

        $mode = match (true) {
            $user instanceof User => 'join',
            $acceptance->hasAccount($invitation) => 'sign-in',
            default => 'create',
        };

        if ($mode === 'sign-in') {
            // After sign-in, Fortify's response returns to this page (WorkspaceLanding honours the intended URL).
            $request->session()->put('url.intended', route('invitations.show', ['token' => $token]));
        }

        return response()->view('pages.invitations.accept', [
            'invitation' => $invitation,
            'organization' => $invitation->organization,
            'token' => $token,
            'mode' => $mode,
        ]);
    }

    public function store(Request $request, string $token, InvitationAcceptance $acceptance): RedirectResponse|Response
    {
        $signedIn = $request->user();

        try {
            if ($signedIn instanceof User) {
                $accepted = $acceptance->acceptAsExistingUser($token, $signedIn);
            } else {
                $accepted = $acceptance->acceptAsNewUser($token, $request->only('name', 'phone', 'password'));
                Auth::guard('web')->login($accepted->user);
                $request->session()->regenerate();
            }
        } catch (InvitationUnavailable) {
            return $this->unavailable();
        } catch (InvitationNeedsSignIn) {
            return redirect()->route('invitations.show', ['token' => $token]);
        } catch (InvitationForAnotherAccount) {
            $invitation = $acceptance->find($token);

            return $invitation !== null ? $this->wrongAccount($invitation) : $this->unavailable();
        }

        $request->session()->forget('url.intended');

        return redirect()
            ->route('workspace.home', $accepted->organization)
            ->with('status', __('Welcome! You have joined :organization.', ['organization' => $accepted->organization->name]));
    }

    private function unavailable(): Response
    {
        return response()->view('pages.invitations.unavailable', [], 404);
    }

    private function wrongAccount(Invitation $invitation): Response
    {
        return response()->view('pages.invitations.wrong-account', ['organization' => $invitation->organization], 403);
    }
}
