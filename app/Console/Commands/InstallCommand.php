<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\AppVersion;
use App\Installer\CronLine;
use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\InstallationException;
use App\Installer\InstallationSteps;
use App\Installer\MailSettings;
use App\Installer\Migrations\MigrationProgress;
use App\Installer\Requirements\CheckStatus;
use App\Installer\Requirements\RequirementsChecker;
use App\Installer\SiteSettings;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Command-line install (INSTALLER.md § 7): the wizard's steps without a
 * browser, for SSH and VPS hosts. Every answer is collected and checked
 * before anything is written. Passwords come from a prompt or a file, never
 * an argument (shell history). Safe to run again after an interruption.
 */
final class InstallCommand extends Command
{
    protected $signature = 'goproperty:install
        {--url= : Site address, e.g. https://goproperty.ng}
        {--db-driver= : mysql (default) or mariadb}
        {--db-host= : Database host (default localhost)}
        {--db-port= : Database port (default 3306)}
        {--db-name= : Database name}
        {--db-user= : Database user}
        {--db-password-file= : File holding the database password}
        {--mail-host= : SMTP host for outgoing email}
        {--mail-port= : SMTP port (default 587)}
        {--mail-user= : SMTP username}
        {--mail-password-file= : File holding the SMTP password}
        {--mail-from= : Address emails are sent from}
        {--mail-test= : Send a test email to this address}
        {--admin-name= : First super-admin\'s name}
        {--admin-email= : First super-admin\'s work email}
        {--admin-password-file= : File holding the first super-admin\'s password}';

    protected $description = 'Install GoProperty from the command line (use --env=production or --env=staging)';

    public function handle(InstallationSteps $steps, RequirementsChecker $requirements, EnvironmentFile $environment, CronLine $cron): int
    {
        if (! $steps->installerOpen()) {
            $this->components->error('GoProperty is already installed, so the installer is closed. Recovery is from the command line (INSTALLER.md § 9).');

            return self::FAILURE;
        }

        $this->components->info('Installing GoProperty '.AppVersion::current());

        if (! $this->serverCheck($requirements)) {
            return self::FAILURE;
        }

        try {
            $site = $this->site();
            $database = $this->database();
            $mail = $this->mail();
            $mailTest = $this->mailTest($mail);
            [$adminName, $adminEmail, $adminPassword] = $this->admin($steps);

            $this->components->task('Application key', function () use ($steps, $environment): bool {
                $steps->ensureApplicationKey($environment);

                return true;
            });

            $probe = $steps->configureSite($environment, $site, $database);
            $this->components->twoColumnDetail('Database', (string) $probe->serverVersion);

            $steps->buildDatabase(function (MigrationProgress $progress): void {
                $this->components->twoColumnDetail('Building the database', $progress->label());
            });

            if ($mail !== null) {
                $steps->configureMail($environment, $mail);
            }

            if ($mailTest !== null) {
                $this->components->task("Test email to {$mailTest}", function () use ($steps, $mailTest): bool {
                    $steps->sendTestEmail($mailTest);

                    return true;
                });
            }

            $steps->createSuperAdmin($adminName, $adminEmail, $adminPassword);
            $this->components->twoColumnDetail('First super-admin', $adminEmail);

            $result = $steps->finalize($environment);
            $record = $steps->writeInstallationRecord($result, $site, $database);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        } catch (InstallationException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('GoProperty is installed.');
        $this->components->twoColumnDetail('Console (bookmark it; it is not linked anywhere)', $site->url.'/'.$result->adminPath);
        $this->newLine();
        $this->line('  Installation record, with APP_KEY (store it offline in two places, then delete it):');
        $this->line('  '.$record);
        $this->newLine();
        $this->line('  Add this cron job, every minute (cPanel → Cron Jobs; Plesk → Scheduled Tasks):');
        $this->line('  '.$cron->line(base_path()));
        $this->newLine();

        if ($mail === null) {
            $this->components->warn('Email is not set up. Set MAIL_* in .env (SMTP from Postmark, Mailgun or SES), then send a test.');
        } elseif ($mailTest === null) {
            $this->components->warn('Email was not tested. Run the install with --mail-test next time, or send one from the console.');
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        $this->components->warn('File storage (S3-compatible) is not set up by the command-line install yet: set AWS_* in .env.');
        $this->line('  Two-factor sign-in is set up at the first console sign-in. Then run: php artisan goproperty:check-installation');

        return self::SUCCESS;
    }

    private function serverCheck(RequirementsChecker $requirements): bool
    {
        $report = $requirements->check();

        foreach ($report->checks as $check) {
            if ($check->blocks()) {
                $this->components->error("{$check->label}: {$check->detail}");
                $this->line('    cPanel: '.$check->cpanel);
                $this->line('    Plesk:  '.$check->plesk);
            } elseif ($check->status === CheckStatus::Warn && $check->group === 'Advisory') {
                $this->components->warn("{$check->label}: {$check->detail}");
            }
        }

        if (! $report->passed()) {
            $this->components->error('Fix the items above, then run the install again.');

            return false;
        }

        $this->components->twoColumnDetail('Server check', 'passed');

        return true;
    }

    private function site(): SiteSettings
    {
        $url = $this->value('url', 'Site address', 'https://goproperty.ng', null, fn (string $value) => new SiteSettings($value, 'production'));
        $environment = $this->option('env');

        if (! is_string($environment) || $environment === '') {
            $environment = $this->input->isInteractive()
                ? (string) select('Is this production or staging?', ['production' => 'Production', 'staging' => 'Staging'])
                : throw new InvalidArgumentException('Pass --env=production or --env=staging.');
        }

        return new SiteSettings($url, $environment);
    }

    private function database(): DatabaseSettings
    {
        $driver = $this->option('db-driver');

        if (! is_string($driver) || $driver === '') {
            $driver = $this->input->isInteractive()
                ? (string) select('Database type', ['mysql' => 'MySQL 8', 'mariadb' => 'MariaDB 10.6 or newer'])
                : 'mysql';
        }

        $host = $this->value('db-host', 'Database host', 'localhost', 'localhost');
        $port = $this->value('db-port', 'Database port', '3306', '3306');
        $name = $this->value('db-name', 'Database name', 'cpaneluser_goproperty');
        $user = $this->value('db-user', 'Database user', 'cpaneluser_gp');
        $password = $this->passwordInput('db-password-file', 'Database password', required: false);

        if (preg_match('/^\d{1,5}$/D', $port) !== 1) {
            throw new InvalidArgumentException('The database port must be a number.');
        }

        return new DatabaseSettings($driver, $host, (int) $port, $name, $user, $password);
    }

    private function mail(): ?MailSettings
    {
        $host = $this->option('mail-host');

        if (! is_string($host) || $host === '') {
            if (! $this->input->isInteractive() || ! confirm('Set up outgoing email (SMTP) now?', default: true)) {
                return null;
            }
        }

        $host = $this->value('mail-host', 'SMTP host', 'smtp.postmarkapp.com');
        $port = $this->value('mail-port', 'SMTP port', '587', '587');
        $user = $this->value('mail-user', 'SMTP username', '', '');
        $password = $this->passwordInput('mail-password-file', 'SMTP password', required: false);
        $from = $this->value('mail-from', 'Send emails from', 'no-reply@goproperty.ng');

        if (preg_match('/^\d{1,5}$/D', $port) !== 1) {
            throw new InvalidArgumentException('The mail port must be a number.');
        }

        return new MailSettings($host, (int) $port, $user, $password, $from);
    }

    private function mailTest(?MailSettings $mail): ?string
    {
        $to = $this->option('mail-test');

        if (is_string($to) && $to !== '') {
            if ($mail === null) {
                throw new InvalidArgumentException('--mail-test needs the email settings (--mail-host and --mail-from).');
            }

            return $to;
        }

        if ($mail !== null && $this->input->isInteractive()) {
            return text('Send a test email to', required: true);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function admin(InstallationSteps $steps): array
    {
        $name = $this->value('admin-name', 'First super-admin\'s name', 'Ada Obi');
        $email = $this->value('admin-email', 'Their work email', 'ada@goproperty.ng');
        $password = $this->passwordInput('admin-password-file', 'Their password (12+ characters)', required: true, confirm: true);

        $steps->validateSuperAdmin($name, $email, $password);

        return [$name, $email, $password];
    }

    /**
     * An option, or a prompt when interactive, or the default.
     *
     * @param  (callable(string): mixed)|null  $check  throws InvalidArgumentException on a bad value
     */
    private function value(string $option, string $label, string $placeholder, ?string $default = null, ?callable $check = null): string
    {
        $value = $this->option($option);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! $this->input->isInteractive()) {
            return $default ?? throw new InvalidArgumentException("Pass --{$option}.");
        }

        return text($label, placeholder: $placeholder, default: $default ?? '', required: $default === null, validate: function (string $value) use ($check): ?string {
            if ($check === null) {
                return null;
            }

            try {
                $check($value);
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }

            return null;
        });
    }

    /** A password from a file (trailing new line removed) or a hidden prompt. */
    private function passwordInput(string $option, string $label, bool $required, bool $confirm = false): string
    {
        $path = $this->option($option);

        if (is_string($path) && $path !== '') {
            if (! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException("Cannot read the file given to --{$option}.");
            }

            if ((fileperms($path) & 0077) !== 0) {
                $this->components->warn("The file given to --{$option} can be read by other users on this server. Use chmod 600, or delete it after the install.");
            }

            $secret = rtrim((string) file_get_contents($path), "\r\n");

            if ($required && $secret === '') {
                throw new InvalidArgumentException("The file given to --{$option} is empty.");
            }

            return $secret;
        }

        if (! $this->input->isInteractive()) {
            if ($required) {
                throw new InvalidArgumentException("Pass --{$option} (passwords are never accepted as arguments).");
            }

            return '';
        }

        $secret = password($label, required: $required);

        if ($confirm && password('Type it again') !== $secret) {
            throw new InvalidArgumentException('The two passwords differ.');
        }

        return $secret;
    }
}
