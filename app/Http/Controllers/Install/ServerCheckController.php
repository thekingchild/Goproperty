<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\Requirements\CheckStatus;
use App\Installer\Requirements\RequirementCheck;
use App\Installer\Requirements\RequirementsChecker;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 2 (INSTALLER.md § 5): the detailed server report, grouped, with how
 * to fix each failure in cPanel and in Plesk. Blocking failures stop here.
 */
final class ServerCheckController extends WizardController
{
    public function show(Request $request, RequirementsChecker $requirements): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Server)) !== null) {
            return $blocked;
        }

        $report = $requirements->check($request);
        $groups = [];

        foreach ($report->checks as $check) {
            $groups[$check->group][] = [
                'label' => $check->label,
                'status' => $check->status === CheckStatus::Pass ? 'pass' : ($check->blocks() ? 'fail' : 'warn'),
                'detail' => $check->status === CheckStatus::Pass ? '' : $check->detail,
                'cpanel' => $check->status === CheckStatus::Pass ? null : $check->cpanel,
                'plesk' => $check->status === CheckStatus::Pass ? null : $check->plesk,
            ];
        }

        return $this->screen($request, WizardStep::Server, 'installer.steps.server', $this->messages($request) + [
            'groups' => $groups,
            'passed' => $report->passed(),
            'failures' => count($report->failures()),
            'warnings' => count(array_filter($report->checks, fn (RequirementCheck $check): bool => $check->status === CheckStatus::Warn)),
        ]);
    }

    public function store(Request $request, RequirementsChecker $requirements): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Server)) !== null) {
            return $blocked;
        }

        if (! $requirements->check($request)->passed()) {
            return $this->back($request, WizardStep::Server, 'Fix the items marked ✗, then check again.');
        }

        $request->session()->put(WizardProgress::SERVER, true);

        return $this->completed($request, WizardStep::Site);
    }
}
