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
 * The organization picker (/dashboard). Someone who belongs to no
 * organization at all is either a tenant (they never get an
 * `organization_user` row, P03 decision 4), who goes to their portal
 * (PortalLanding), or has just signed up and continues signup at the
 * organization step (ONBOARDING_COMPLIANCE.md § 2).
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, PortalLanding $portals): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->organizations()->doesntExist()) {
            $portal = $portals->url($user);

            return $portal !== null ? redirect()->to($portal) : redirect()->route('onboarding.organization');
        }

        return view('dashboard');
    }
}
