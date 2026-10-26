<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\CronLine;
use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\InstallationSteps;
use App\Installer\SiteSettings;
use App\Installer\Web\ConsoleHandover;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use App\Models\PlatformStaff;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Step 7 (INSTALLER.md § 5, "Finish"). The finalizer closes the installer
 * for good (cleanup checkpoint first, console path, secrets deleted, lock
 * and database row, caches); then the install goes into the audit log and
 * the owner is handed a signed-in console session.
 *
 * The finish screen is the reply to that post, shown once: /install is a
 * 404 from now on, so reloading it cannot show the console path again.
 */
final class FinishController extends WizardController
{
    public function show(Request $request, EnvironmentFile $environment): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Finish)) !== null) {
            return $blocked;
        }

        return $this->screen($request, WizardStep::Finish, 'installer.steps.finish', $this->messages($request) + [
            'services' => $this->services($request, $environment),
        ]);
    }

    public function store(Request $request, EnvironmentFile $environment, InstallationSteps $steps, ConsoleHandover $handover, CronLine $cron, Config $config): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Finish)) !== null) {
            return $blocked;
        }

        $staff = PlatformStaff::query()->orderBy('id')->firstOrFail();
        $services = $this->services($request, $environment);

        try {
            $site = new SiteSettings((string) $environment->get('APP_URL'), (string) $environment->get('APP_ENV'));
        } catch (InvalidArgumentException $exception) {
            return $this->back($request, WizardStep::Finish, $exception->getMessage().' Check the site address on the "Site & database" step.');
        }

        $name = $config->get('database.default');
        $connection = $config->get('database.connections.'.(is_string($name) ? $name : 'mysql'));
        $database = DatabaseSettings::fromConnection(is_array($connection) ? $connection : []);

        $result = $steps->finalize($environment);
        $warnings = [...$result->warnings, ...array_filter([$steps->recordInstallation($staff, 'web')])];
        $record = $steps->installationRecord($result, $site, $database);

        try {
            $cookie = $handover->signIn($staff, $request, $environment);
        } catch (Throwable $exception) {
            Log::channel('install')->error('The console session could not be started', ['error' => $exception->getMessage()]);
            $cookie = null;
        }

        $domain = $config->get('app.admin_domain');
        $console = is_string($domain) && trim($domain) !== ''
            ? 'https://'.strtolower(trim($domain))
            : $site->url.'/'.$result->adminPath;
        $line = $cron->line(base_path());

        $response = $this->page->render($request, WizardStep::Finish, WizardStep::Finish, 'installer.steps.finished', 'Installed', [
            'console' => $console,
            'signedIn' => $cookie !== null,
            'heartbeat' => $cookie !== null ? $request->getBaseUrl().'/'.$result->adminPath.'/system/heartbeat' : null,
            'cronLine' => $line,
            'cronCommand' => (string) preg_replace('/^(\S+\s+){5}/', '', $line),
            'record' => $record,
            'services' => $services,
            'debugOff' => filter_var($environment->get('APP_DEBUG') ?? 'true', FILTER_VALIDATE_BOOL) === false,
            'warnings' => $warnings,
        ]);

        if ($cookie !== null) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /** @return array{mail: string, storage: string} tested, skipped or unknown */
    private function services(Request $request, EnvironmentFile $environment): array
    {
        $session = $request->session();
        $mail = $session->get(WizardProgress::MAIL);
        $storage = $session->get(WizardProgress::STORAGE);

        return [
            'mail' => is_string($mail) ? $mail : 'unknown',
            'storage' => is_string($storage) ? $storage : ((string) $environment->get('AWS_BUCKET') !== '' ? 'unknown' : 'skipped'),
        ];
    }
}
