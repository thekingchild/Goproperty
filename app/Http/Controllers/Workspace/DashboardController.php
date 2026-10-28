<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\PortalLanding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization picker (/dashboard). Someone with no workspace is a
 * tenant (they never get an `organization_user` row, P03 decision 4), a
 * property owner (a member holding only `property-owner`, P03-15), both,
 * or has just signed up: they go to their only portal (PortalLanding),
 * see the portals they belong in when there are several, or continue
 * signup at the organization step when they belong to no organization at
 * all (ONBOARDING_COMPLIANCE.md § 2).
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, PortalLanding $portals): View|RedirectResponse
    {
        $user = $request->user();
        $belongsIn = [];

        if ($user instanceof User && $user->workspaceOrganizationIds() === []) {
            $belongsIn = $portals->of($user);

            if (count($belongsIn) === 1) {
                return redirect()->to($belongsIn[0]['url']);
            }

            if ($belongsIn === [] && $user->organizations()->doesntExist()) {
                return redirect()->route('onboarding.organization');
            }
        }

        return view('dashboard', ['portals' => $belongsIn]);
    }
}
