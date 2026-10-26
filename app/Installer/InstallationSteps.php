<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Database\DatabaseProbe;
use App\Installer\Database\DatabaseSettings;
use App\Installer\Database\ProbeResult;
use App\Installer\Finishing\Finalizer;
use App\Installer\Finishing\FinalizeResult;
use App\Installer\Migrations\BatchedMigrator;
use App\Installer\Migrations\MigrationProgress;
use App\Installer\Support\AtomicFile;
use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use Closure;
use Database\Seeders\InstallSeeder;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/**
 * The installer's steps (INSTALLER.md § 5), shared by `goproperty:install`
 * and the web wizard (P01-09). Each step is safe to repeat, so an install
 * interrupted at any point continues where it stopped.
 */
final class InstallationSteps
{
    /** The connection an install runs on, built from the owner's settings. */
    public const string CONNECTION = 'goproperty_install';

    public const string TIMEZONE = 'Africa/Lagos';

    public function __construct(
        private readonly Container $app,
        private readonly Config $config,
        private readonly DatabaseManager $db,
        private readonly InstallationMarkers $markers,
        private readonly InstallationRecord $record,
        private readonly DatabaseProbe $probe,
        private readonly BatchedMigrator $migrator,
        private readonly Finalizer $finalizer,
    ) {}

    public function installerOpen(): bool
    {
        return $this->markers->installerOpen();
    }

    /**
     * Creates `.env` from `.env.example` if needed and makes sure APP_KEY
     * exists, reusing a valid one. The key is switched into this process.
     * True when a new key was generated.
     */
    public function ensureApplicationKey(EnvironmentFile $environment): bool
    {
        $environment->ensureExists();
        $cipher = $this->config->get('app.cipher', 'AES-256-CBC');
        $cipher = is_string($cipher) ? $cipher : 'AES-256-CBC';
        $key = $environment->get('APP_KEY');
        $generated = false;

        if (! ApplicationKey::usable($key, $cipher)) {
            $key = 'base64:'.base64_encode(Encrypter::generateKey($cipher));
            $environment->set(['APP_KEY' => $key]);
            $generated = true;
            Log::channel('install')->info('Generated APP_KEY');
        }

        $this->config->set('app.key', $key);
        $this->app->forgetInstance('encrypter');

        return $generated;
    }

    /**
     * Step 3: tests the database, then writes the site and database settings
     * and records them in the checkpoint. Refuses other database settings
     * than the ones an unfinished install started with (§ 6).
     */
    public function configureSite(EnvironmentFile $environment, SiteSettings $site, DatabaseSettings $database): ProbeResult
    {
        $checkpoint = $this->markers->checkpoint();
        $recorded = $checkpoint->read()->fingerprint !== null;

        if ($recorded && ! $checkpoint->matches($database)) {
            throw new InstallationException('These database settings differ from the ones this install started with. '
                .'Use the same database, or start over as described in INSTALLER.md § 9.');
        }

        $result = $this->probe->test($database);

        if (! $result->ok) {
            throw new InstallationException($result->message);
        }

        $environment->set([
            'APP_ENV' => $site->environment,
            'APP_DEBUG' => false,
            'APP_URL' => $site->url,
            'APP_TIMEZONE' => self::TIMEZONE,
            'DB_CONNECTION' => $database->driver,
            'DB_HOST' => $database->host,
            'DB_PORT' => $database->port,
            'DB_DATABASE' => $database->database,
            'DB_USERNAME' => $database->username,
            'DB_PASSWORD' => $database->password(),
            // Shared-hosting defaults (ARCHITECTURE.md § 12.1). Cache and
            // sessions use files until the tables exist; the finalizer
            // moves them to the database.
            'HOSTING_PROFILE' => 'shared',
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file',
        ]);

        $this->useDatabase($database);

        if (! $recorded) {
            $checkpoint->write(InstallStage::Configured, $database);
        }

        $this->recordStage(InstallStage::Configured);
        Log::channel('install')->info('Site and database configured', ['environment' => $site->environment, 'server' => $result->serverVersion]);

        return $result;
    }

    /** Points this process's default connection at the owner's database. */
    public function useDatabase(DatabaseSettings $database): void
    {
        $base = $this->config->get('database.connections.'.$database->driver);

        $this->db->purge(self::CONNECTION);
        $this->config->set('database.connections.'.self::CONNECTION, $database->connectionConfig(is_array($base) ? $base : [], 10));
        $this->config->set('database.default', self::CONNECTION);
    }

    /**
     * Step 4: every pending migration, in batches, then the install seeders.
     *
     * @param  (Closure(MigrationProgress): void)|null  $progress  called after each batch
     */
    public function buildDatabase(?Closure $progress = null): MigrationProgress
    {
        $this->recordStage(InstallStage::Migrating);

        do {
            $batch = $this->migrator->runBatch();

            if ($batch->failed()) {
                throw new InstallationException("Migration {$batch->failedMigration} failed: {$batch->error} "
                    .'Run the install again to retry. If it fails again on a new install, empty the database and start this step again (INSTALLER.md § 6).');
            }

            // The first batch creates installation_state.
            $this->recordStage(InstallStage::Migrating);

            if ($progress !== null) {
                $progress($batch->progress);
            }
        } while (! $batch->progress->finished());

        $seeder = $this->app->make(InstallSeeder::class);
        $seeder->setContainer($this->app)->__invoke();
        Log::channel('install')->info('Database built and seeded', ['migrations' => $batch->progress->total]);

        return $batch->progress;
    }

    /** Step 5: writes SMTP settings and switches them into this process. */
    public function configureMail(EnvironmentFile $environment, MailSettings $mail): void
    {
        $environment->set([
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => $mail->scheme(),
            'MAIL_HOST' => $mail->host,
            'MAIL_PORT' => $mail->port,
            'MAIL_USERNAME' => $mail->username,
            'MAIL_PASSWORD' => $mail->password(),
            'MAIL_FROM_ADDRESS' => $mail->fromAddress,
        ]);

        $this->config->set('mail.default', 'smtp');
        $this->config->set('mail.from.address', $mail->fromAddress);
        $this->config->set('mail.mailers.smtp', array_merge((array) $this->config->get('mail.mailers.smtp'), [
            'transport' => 'smtp',
            'scheme' => $mail->scheme(),
            'host' => $mail->host,
            'port' => $mail->port,
            'username' => $mail->username,
            'password' => $mail->password(),
        ]));

        $this->mailer()->purge('smtp');
    }

    /** Step 5: sends a test email; any failure is reported, not swallowed. */
    public function sendTestEmail(string $to): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallationException('The test email address is not valid.');
        }

        $name = $this->config->get('app.name');
        $name = is_string($name) ? $name : 'GoProperty';

        try {
            $this->mailer()->mailer()->raw(
                "This is a test email from the {$name} installer. Email is working.",
                fn ($message) => $message->to($to)->subject("{$name}: test email"),
            );
        } catch (Throwable $exception) {
            throw new InstallationException('The test email could not be sent: '.$exception->getMessage());
        }

        Log::channel('install')->info('Test email sent');
    }

    /**
     * Step 6: the first staff account. Refused if any staff account exists,
     * except the same email and password again (resuming after a timeout).
     * 2FA is enrolled at first console sign-in (INSTALLER.md § 7).
     *
     * @throws ValidationException
     */
    public function createSuperAdmin(string $name, string $email, #[SensitiveParameter] string $password): PlatformStaff
    {
        $this->validateSuperAdmin($name, $email, $password);

        $existing = PlatformStaff::query()->orderBy('id')->first();

        if ($existing !== null) {
            if (strcasecmp($existing->email, $email) === 0 && Hash::check($password, $existing->password)) {
                $this->recordStage(InstallStage::OwnerReady);

                return $existing;
            }

            throw new InstallationException('A staff account already exists. To continue an interrupted install, use the same email and password as before.');
        }

        $staff = PlatformStaff::query()->create([
            'name' => $name,
            'email' => strtolower($email),
            'password' => $password,
            'status' => StaffStatus::Active,
        ]);

        $this->recordStage(InstallStage::OwnerReady);
        Log::channel('install')->info('First staff account created');

        return $staff;
    }

    /**
     * 12+ characters and not in a known breach (checked by hash prefix only,
     * k-anonymity; if the breach service cannot be reached the check passes).
     *
     * @throws ValidationException
     */
    public function validateSuperAdmin(string $name, string $email, #[SensitiveParameter] string $password): void
    {
        Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:254'],
                'password' => ['required', 'string', Password::min(12)->uncompromised()],
            ],
        )->validate();
    }

    /** Finish (§ 5): closes the installer for good. */
    public function finalize(EnvironmentFile $environment): FinalizeResult
    {
        return $this->finalizer->finalize($environment, AppVersion::current());
    }

    /**
     * The installation record (§ 5): written once, mode 0600, for the owner to
     * store offline. Without APP_KEY, encrypted data cannot be read after a
     * restore. Returns the file's path.
     */
    public function writeInstallationRecord(FinalizeResult $result, SiteSettings $site, DatabaseSettings $database): string
    {
        $path = $this->markers->path('record');
        $key = $this->config->get('app.key');

        AtomicFile::write($path, implode("\n", [
            'GoProperty installation record',
            '',
            'Store this file offline in two places, then delete it from the server.',
            'Without APP_KEY, encrypted data cannot be read after a restore.',
            '',
            'Version:        '.AppVersion::current(),
            'Installed:      '.now()->toIso8601String(),
            'Site address:   '.$site->url,
            'Environment:    '.$site->environment,
            'Console:        '.$site->url.'/'.$result->adminPath,
            'Database:       '.$database->database.' on '.$database->host.':'.$database->port,
            'APP_KEY:        '.(is_string($key) ? $key : ''),
            '',
        ]), 0600);

        return $path;
    }

    /**
     * Moves the checkpoint and the database row forward, never back. The row
     * is skipped until its table exists.
     */
    public function recordStage(InstallStage $stage): void
    {
        $checkpoint = $this->markers->checkpoint();
        $current = $checkpoint->read()->stage;

        if ($current !== null && $current->order() < $stage->order()) {
            $checkpoint->advance($stage);
        }

        $connection = $this->db->connection();

        try {
            $row = $this->record->stage($connection);

            if ($row === null || $row->order() < $stage->order()) {
                $this->record->record($connection, $stage, AppVersion::current());
            }
        } catch (QueryException $exception) {
            if (! InstallationRecord::isMissingTable($exception)) {
                throw $exception;
            }
        }
    }

    private function mailer(): MailManager
    {
        return $this->app->make('mail.manager');
    }
}
