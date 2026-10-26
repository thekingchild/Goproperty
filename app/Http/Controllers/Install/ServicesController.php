<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Installer\EnvironmentFile;
use App\Installer\InstallationException;
use App\Installer\InstallationSteps;
use App\Installer\MailSettings;
use App\Installer\StorageSettings;
use App\Installer\Web\WizardProgress;
use App\Installer\Web\WizardStep;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 5 (INSTALLER.md § 5): outgoing email and S3-compatible file storage,
 * each tested before the owner leaves (a test email to their address; a
 * small file written, read back and deleted) or explicitly skipped, which
 * the finish screen then lists as a next step. Secrets go to `.env`, never
 * to the database.
 */
final class ServicesController extends WizardController
{
    /** SMTP hosts for the providers ARCHITECTURE.md § 12.1 allows. */
    public const array PROVIDERS = [
        'postmark' => ['label' => 'Postmark', 'host' => 'smtp.postmarkapp.com'],
        'mailgun' => ['label' => 'Mailgun', 'host' => 'smtp.mailgun.org'],
        'ses' => ['label' => 'Amazon SES', 'host' => 'email-smtp.eu-west-1.amazonaws.com'],
        'smtp' => ['label' => 'Other SMTP service', 'host' => ''],
    ];

    public function show(Request $request, EnvironmentFile $environment): Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Services)) !== null) {
            return $blocked;
        }

        $session = $request->session();

        return $this->screen($request, WizardStep::Services, 'installer.steps.services', $this->messages($request) + [
            'providers' => self::PROVIDERS,
            'mail' => $session->get(WizardProgress::MAIL),
            'storage' => $session->get(WizardProgress::STORAGE),
            'done' => $this->progress->isDone(WizardStep::Services, $session),
            'values' => [
                'mail_host' => self::saved($environment, 'MAIL_HOST'),
                'mail_port' => self::saved($environment, 'MAIL_PORT', '587'),
                'mail_username' => self::saved($environment, 'MAIL_USERNAME'),
                'mail_from' => self::saved($environment, 'MAIL_FROM_ADDRESS'),
                'storage_endpoint' => self::saved($environment, 'AWS_ENDPOINT'),
                'storage_region' => self::saved($environment, 'AWS_DEFAULT_REGION', 'auto'),
                'storage_bucket' => self::saved($environment, 'AWS_BUCKET'),
                'storage_key' => self::saved($environment, 'AWS_ACCESS_KEY_ID'),
            ],
        ]);
    }

    public function mail(Request $request, EnvironmentFile $environment, InstallationSteps $steps): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Services)) !== null) {
            return $blocked;
        }

        if ($request->input('action') === 'skip') {
            $request->session()->put(WizardProgress::MAIL, 'skipped');

            return $this->next($request);
        }

        try {
            $mail = $this->mailSettings($request, $environment);
            $to = self::input($request, 'mail_test');

            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Enter the address the test email goes to.');
            }

            $steps->configureMail($environment, $mail);
            $steps->sendTestEmail($to);
        } catch (InvalidArgumentException|InstallationException $exception) {
            return $this->back($request, WizardStep::Services, $exception->getMessage(), 'mail');
        }

        $request->session()->put(WizardProgress::MAIL, 'tested');
        $request->session()->flash('installer.status', ['mail' => "Test email sent to {$to}. Check that it arrived (and the spam folder)."]);

        return $this->next($request);
    }

    public function storage(Request $request, EnvironmentFile $environment, InstallationSteps $steps): RedirectResponse|Response
    {
        if (($blocked = $this->blocked($request, WizardStep::Services)) !== null) {
            return $blocked;
        }

        if ($request->input('action') === 'skip') {
            $request->session()->put(WizardProgress::STORAGE, 'skipped');

            return $this->next($request);
        }

        try {
            $secret = $request->input('storage_secret');
            $secret = is_string($secret) && $secret !== '' ? $secret : (string) $environment->get('AWS_SECRET_ACCESS_KEY');

            $steps->configureStorage($environment, new StorageSettings(
                self::input($request, 'storage_endpoint'),
                self::input($request, 'storage_region'),
                self::input($request, 'storage_bucket'),
                self::input($request, 'storage_key'),
                trim($secret),
                $request->boolean('storage_path_style'),
            ));
        } catch (InvalidArgumentException|InstallationException $exception) {
            return $this->back($request, WizardStep::Services, $exception->getMessage(), 'storage');
        }

        $request->session()->put(WizardProgress::STORAGE, 'tested');
        $request->session()->flash('installer.status', ['storage' => 'File storage works: a test file was written, read back and deleted.']);

        return $this->next($request);
    }

    /** Both settled: on to the super-admin; otherwise back to this page. */
    private function next(Request $request): RedirectResponse
    {
        $this->grant->renew();

        if ($this->progress->isDone(WizardStep::Services, $request->session())) {
            $request->session()->reflash();

            return $this->completed($request, WizardStep::Admin);
        }

        return $this->to($request, WizardStep::Services);
    }

    private function mailSettings(Request $request, EnvironmentFile $environment): MailSettings
    {
        $provider = self::input($request, 'mail_provider');
        $host = self::input($request, 'mail_host');
        $host = $host !== '' ? $host : (self::PROVIDERS[$provider]['host'] ?? '');
        $port = self::input($request, 'mail_port');
        $password = $request->input('mail_password');
        $password = is_string($password) && $password !== '' ? $password : (string) $environment->get('MAIL_PASSWORD');

        if (preg_match('/^\d{1,5}$/D', $port) !== 1) {
            throw new InvalidArgumentException('The mail port must be a number.');
        }

        // .env.example's placeholder value.
        if ($password === 'null') {
            $password = '';
        }

        return new MailSettings($host, (int) $port, self::input($request, 'mail_username'), $password, self::input($request, 'mail_from'));
    }

    /**
     * A value saved earlier, ignoring `.env.example`'s development
     * placeholders (Mailpit, us-east-1 and the like).
     */
    private static function saved(EnvironmentFile $environment, string $key, string $default = ''): string
    {
        $value = $environment->get($key);
        $placeholders = ['', 'null', 'mailpit', '1025', 'hello@example.com', 'us-east-1'];

        return is_string($value) && ! in_array($value, $placeholders, true) ? $value : $default;
    }

    private static function input(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? trim($value) : '';
    }
}
