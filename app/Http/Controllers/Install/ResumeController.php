<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\Web\WizardStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Continue the install": the first step that is not done yet. */
final class ResumeController extends WizardController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $current = $this->progress->current($request->session());

        return $this->to($request, $current === WizardStep::Unlock ? WizardStep::Server : $current);
    }
}
