<?php

declare(strict_types=1);

namespace Modules\Properties\Http\Controllers;

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
use Modules\Properties\OwnerPortal\OwnerInvitationAcceptance;

/**
 * An owner's portal invitation link, /owner/invitations/{token} (P03-15;
 * the tenant invitation page's design, UX_DESIGN.md § 3.3), on the
 * sign-in pages' split layout. Rules in OwnerPortal\OwnerInvitationAcceptance:
 * a new person sets a password and is signed in (then may add a passkey);
 * an account that already has the address or number signs in first and
 * comes back; anyone else signed in is refused (403), and so is an account
 * that already follows another owner's buildings in that organization.
 * Unknown, used, revoked and expired links get one "no longer works" page
 * (404). Never while GoProperty staff impersonate (it signs in and links
 * accounts). Rate-limited per IP.
 */
#[BlockedWhileImpersonating]
final class OwnerInvitationController
{
    public function show(Request $request, string $token, OwnerInvitationAcceptance $acceptance): Response
    {
        $opened = $acceptance->find($token);

        if ($opened === null) {
            return self::unavailable();
        }

        $user = $request->user();
        $mode = $acceptance->mode($opened->invitation, $user instanceof User ? $user : null);

        if ($mode === 'wrong-account' || $mode === 'linked-elsewhere') {
            return self::refused($opened->organization, $mode);
        }

        if ($mode === 'sign-in') {
            // After sign-in, Fortify's response returns here (WorkspaceLanding honours the intended URL).
            $request->session()->put('url.intended', route('owner.invitations.show', ['token' => $token]));
        }

        return response()->view('properties::owner-portal.invitation', [
            'invitation' => $opened->invitation,
            'owner' => $opened->owner,
            'organization' => $opened->organization,
            'token' => $token,
            'mode' => $mode,
        ]);
    }

    public function store(Request $request, string $token, OwnerInvitationAcceptance $acceptance): RedirectResponse|Response
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
            return self::unavailable();
        } catch (InvitationNeedsSignIn) {
            return redirect()->route('owner.invitations.show', ['token' => $token]);
        } catch (InvitationForAnotherAccount) {
            $opened = $acceptance->find($token);
            $account = $request->user();

            return $opened !== null
                ? self::refused($opened->organization, $account instanceof User ? $acceptance->mode($opened->invitation, $account) : 'wrong-account')
                : self::unavailable();
        }

        $request->session()->forget('url.intended');

        if ($accepted->newAccount) {
            return redirect()->route('owner.welcome');
        }

        return redirect()->route('owner.home')
            ->with('status', __(':organization added you as the owner of your buildings there.', ['organization' => $accepted->organization->name]));
    }

    public static function unavailable(): Response
    {
        return response()->view('properties::owner-portal.unavailable', [], 404);
    }

    public static function refused(Organization $organization, string $mode): Response
    {
        return response()->view('properties::owner-portal.wrong-account', ['organization' => $organization, 'linkedElsewhere' => $mode === 'linked-elsewhere'], 403);
    }
}
