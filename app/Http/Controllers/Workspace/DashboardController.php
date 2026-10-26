<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The organization picker (/dashboard). Someone who belongs to no
 * organization at all has just signed up (tenants and invited members
 * always belong to one), so they continue signup at the organization step
 * (ONBOARDING_COMPLIANCE.md § 2).
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->organizations()->doesntExist()) {
            return redirect()->route('onboarding.organization');
        }

        return view('dashboard');
    }
}
