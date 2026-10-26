<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\WorkspaceLanding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The organization switcher and picker (ONBOARDING_COMPLIANCE.md § 1): opens
 * an organization's workspace and remembers it as the one to land on next
 * time. Unknown organizations and non-members get the same 404 as
 * /o/{slug} (OrganizationPolicy::switchTo).
 */
final class SwitchOrganizationController extends Controller
{
    public function __invoke(Request $request, string $slug, WorkspaceLanding $landing): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        $organization = $user->organizations()->where('slug', $slug)->first();

        if ($organization === null || Gate::forUser($user)->denies('switchTo', $organization)) {
            throw new NotFoundHttpException;
        }

        $landing->remember($user, $organization);

        return redirect()->route('workspace.home', $organization);
    }
}
