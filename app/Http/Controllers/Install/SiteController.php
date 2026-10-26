<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\Database\DatabaseProbe;
use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\InstallationException;
use App\Installer\InstallationSteps;
use App\Installer\InstallStage;
use App\Installer\SiteSettings;
use App\Installer\Web\WizardStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 3 (INSTALLER.md § 5): site address, environment and database.
 * "Test connection" probes with a temporary connection and writes nothing;
 * "Save and continue" probes again, then writes `.env`. The password is
 * never shown again; left empty on a later visit, the saved one is kept.
 */
final class SiteController extends WizardController
{
    public function show(Request $request, EnvironmentFile $environment): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Site)) !== null) {
            return $blocked;
        }

        $configured = $this->progress->reached(InstallStage::Configured);
        $url = $environment->get('APP_URL');
        $driver = $environment->get('DB_CONNECTION');

        return $this->screen($request, WizardStep::Site, 'installer.steps.site', $this->messages($request) + [
            'configured' => $configured,
            'values' => [
                'site_url' => $configured && is_string($url) ? $url : $request->getSchemeAndHttpHost(),
                'environment' => $configured ? (string) $environment->get('APP_ENV') : 'production',
                'db_driver' => $configured && is_string($driver) ? $driver : 'mysql',
                'db_host' => $configured ? (string) $environment->get('DB_HOST') : 'localhost',
                'db_port' => $configured ? (string) $environment->get('DB_PORT') : '3306',
                'db_name' => $configured ? (string) $environment->get('DB_DATABASE') : '',
                'db_user' => $configured ? (string) $environment->get('DB_USERNAME') : '',
            ],
        ]);
    }

    public function store(Request $request, EnvironmentFile $environment, InstallationSteps $steps, DatabaseProbe $probe): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Site)) !== null) {
            return $blocked;
        }

        try {
            $site = new SiteSettings(self::input($request, 'site_url'), self::input($request, 'environment'));
            $database = $this->database($request, $environment);
        } catch (InvalidArgumentException $exception) {
            return $this->back($request, WizardStep::Site, $exception->getMessage());
        }

        if ($request->input('action') === 'test') {
            $result = $probe->test($database);

            return $this->back($request, WizardStep::Site, $result->message, 'probe', error: ! $result->ok);
        }

        try {
            $steps->configureSite($environment, $site, $database);
        } catch (InstallationException|InvalidArgumentException $exception) {
            return $this->back($request, WizardStep::Site, $exception->getMessage());
        }

        return $this->completed($request, WizardStep::Database);
    }

    private function database(Request $request, EnvironmentFile $environment): DatabaseSettings
    {
        $port = self::input($request, 'db_port');
        $password = $request->input('db_password');
        $password = is_string($password) ? $password : '';

        if ($password === '' && $this->progress->reached(InstallStage::Configured)) {
            $password = (string) $environment->get('DB_PASSWORD');
        }

        if (preg_match('/^\d{1,5}$/D', $port) !== 1) {
            throw new InvalidArgumentException('The database port must be a number.');
        }

        return new DatabaseSettings(
            self::input($request, 'db_driver'),
            self::input($request, 'db_host'),
            (int) $port,
            self::input($request, 'db_name'),
            self::input($request, 'db_user'),
            $password,
        );
    }

    private static function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
