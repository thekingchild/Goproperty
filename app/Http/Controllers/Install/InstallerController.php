<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\AppVersion;
use App\Installer\Requirements\RequirementsChecker;
use App\Installer\Support\LocalHost;
use App\Installer\Web\UnlockChallenge;
use App\Installer\Web\WizardStep;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The installer's first screen (INSTALLER.md § 4): version, a public
 * pass/fail requirements summary with no paths or values, and the two ways
 * to unlock. It works before APP_KEY exists, so it uses no session, cookies
 * or database.
 */
final class InstallerController
{
    public function __construct(
        private readonly RequirementsChecker $requirements,
        private readonly UnlockChallenge $challenge,
        private readonly InstallerPage $page,
    ) {}

    public function show(Request $request): Response
    {
        $notice = match ($request->query('session')) {
            'expired' => 'Your installer session ended after '.config('installer.grant_minutes', 60).' minutes without a completed step. Unlock again to carry on where you stopped.',
            'missing' => 'Unlock the installer in this browser to continue. Nothing done so far is lost.',
            default => null,
        };

        return $this->screen($request, notice: is_string($notice) ? $notice : null);
    }

    /** The first screen, with an error from a refused unlock. */
    public function screen(Request $request, ?string $error = null, ?string $notice = null, int $status = 200): Response
    {
        $report = $this->requirements->check($request);

        try {
            $phrase = $this->challenge->issue();
        } catch (Throwable) {
            $phrase = null;
        }

        return $this->page->render($request, WizardStep::Unlock, WizardStep::Unlock, 'installer.steps.unlock', 'Unlock', [
            'version' => AppVersion::current(),
            'summary' => $report->publicSummary(),
            'ready' => $report->passed(),
            'phrase' => $phrase,
            'minutes' => $this->challenge->minutes(),
            'error' => $error,
            'notice' => $notice,
            'insecure' => ! self::secureEnough($request),
        ], $status);
    }

    /** HTTPS, except on a local test copy (INSTALLER.md § 4). */
    public static function secureEnough(Request $request): bool
    {
        return $request->isSecure() || LocalHost::is($request->getHost());
    }
}
