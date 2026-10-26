<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Onboarding\OnboardingWizard;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The workspace dashboard (/o/{organization}). While the organization's
 * signup is unfinished, whoever can carry it on is sent back to the step
 * they left (ONBOARDING_COMPLIANCE.md § 2: leaving mid-wizard resumes
 * there). Afterwards the dashboard shows the setup checklist until every
 * item is done.
 */
final class WorkspaceHomeController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context, OnboardingWizard $wizard, OnboardingChecklist $checklist): View|RedirectResponse
    {
        $organization = $context->get();
        $user = $request->user();
        $state = $wizard->state();

        if ($user instanceof User && $state !== null && ! $state->isComplete()
            && Gate::forUser($user)->allows('continueSignup', [$organization, $state->step])) {
            return redirect()->route('workspace.setup', $organization);
        }

        $showChecklist = $user instanceof User
            && $checklist->isOpen()
            && Gate::forUser($user)->allows('viewSetupChecklist', $organization);

        return view('surfaces.workspace', [
            'organization' => $organization,
            'checklist' => $showChecklist ? $checklist->items() : null,
        ]);
    }
}
