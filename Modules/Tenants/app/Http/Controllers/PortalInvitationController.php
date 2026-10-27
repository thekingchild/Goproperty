<?php

declare(strict_types=1);

namespace Modules\Tenants\Http\Controllers;

use App\Members\InvitationForAnotherAccount;
use App\Members\InvitationNeedsSignIn;
use App\Members\InvitationUnavailable;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Tenants\Portal\PortalInvitationAcceptance;

/**
 * A tenant's portal invitation link, /portal/invitations/{token}
 * (UX_DESIGN.md § 3.3 "Accept invitation", ONBOARDING_COMPLIANCE.md § 2
 * "Tenants"; P03-01b), on the sign-in pages' split layout. Rules in
 * Portal\PortalInvitationAcceptance: a new person sets a password and is
 * signed in (then may add a passkey); an account that already has the
 * address or number signs in first and comes back; anyone else signed in
 * is refused (403). Unknown, used, revoked and expired links get one "no
 * longer works" page (404). Never while GoProperty staff impersonate (it
 * signs in and links accounts). Rate-limited per IP.
 */
#[BlockedWhileImpersonating]
final class PortalInvitationController
{
    public function show(Request $request, string $token, PortalInvitationAcceptance $acceptance): Response
    {
        $opened = $acceptance->find($token);

        if ($opened === null) {
            return self::unavailable('invitation');
        }

        $user = $request->user();
        $mode = $acceptance->mode($opened->invitation, $user instanceof User ? $user : null);

        if ($mode === 'wrong-account') {
            return self::wrongAccount($opened->organization, 'invitation');
        }

        if ($mode === 'sign-in') {
            // After sign-in, Fortify's response returns here (WorkspaceLanding honours the intended URL).
            $request->session()->put('url.intended', route('portal.invitations.show', ['token' => $token]));
        }

        return response()->view('tenants::portal.invitation', [
            'invitation' => $opened->invitation,
            'tenant' => $opened->tenant,
            'organization' => $opened->organization,
            'token' => $token,
            'mode' => $mode,
        ]);
    }

    public function store(Request $request, string $token, PortalInvitationAcceptance $acceptance): RedirectResponse|Response
    {
        $signedIn = $request->user();

        try {
            if ($signedIn instanceof User) {
                $accepted = $acceptance->acceptAsExistingUser($token, $signedIn);
            } else {
                $accepted = $acceptance->acceptAsNewUser($token, $request->only('name', 'password'));
                Auth::guard('web')->login($accepted->user);
                $request->session()->regenerate();
                // They have just chosen it: adding a passkey next needs no second prompt.
                $request->session()->passwordConfirmed();
            }
        } catch (InvitationUnavailable) {
            return self::unavailable('invitation');
        } catch (InvitationNeedsSignIn) {
            return redirect()->route('portal.invitations.show', ['token' => $token]);
        } catch (InvitationForAnotherAccount) {
            $opened = $acceptance->find($token);

            return $opened !== null ? self::wrongAccount($opened->organization, 'invitation') : self::unavailable('invitation');
        }

        $request->session()->forget('url.intended');

        if ($accepted->newAccount) {
            return redirect()->route('portal.welcome');
        }

        return redirect()->route('portal.home')
            ->with('status', __(':organization added you as a tenant.', ['organization' => $accepted->organization->name]));
    }

    public static function unavailable(string $kind): Response
    {
        return response()->view('tenants::portal.unavailable', ['kind' => $kind], 404);
    }

    public static function wrongAccount(Organization $organization, string $kind): Response
    {
        return response()->view('tenants::portal.wrong-account', ['organization' => $organization, 'kind' => $kind], 403);
    }
}
