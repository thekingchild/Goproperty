<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\AppVersion;
use App\Installer\Requirements\RequirementsChecker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer's first screen (INSTALLER.md § 4): version and a public
 * pass/fail requirements summary, with no paths or values. It works before
 * APP_KEY exists, so it uses no session, cookies or database. The unlock
 * forms and wizard steps arrive with the web installer (P01-09).
 */
final class InstallerController
{
    public function show(Request $request, RequirementsChecker $requirements): Response
    {
        $report = $requirements->check($request);

        return response()->view('installer.welcome', [
            'name' => config('app.name'),
            'version' => AppVersion::current(),
            'summary' => $report->publicSummary(),
            'ready' => $report->passed(),
            'stylesheet' => $request->getBasePath().'/install.css',
        ]);
    }
}
