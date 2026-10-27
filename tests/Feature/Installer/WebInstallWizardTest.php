<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use App\Installer\ApplicationKey;
use App\Installer\Database\SchemaSnapshot;
use App\Installer\EnvironmentFile;
use App\Installer\Finishing\AdminPath;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationSteps;
use App\Installer\InstallStage;
use App\Installer\Requirements\ServerEnvironment;
use App\Installer\StorageProbe;
use App\Models\PlatformStaff;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeServer;
use Tests\Support\InstallerBrowser;
use Tests\Support\ScratchDatabase;

/*
 * P01-09 acceptance: the web install walked over HTTP into a real throwaway
 * database (INSTALLER.md § 4–5): file-check unlock, server check, site and
 * database, batched migrations, a real test email (to a local SMTP sink)
 * and a storage test, the first super-admin with TOTP enrolment, and the
 * finish screen. The result matches the command-line install, and
 * afterwards every installer route is a 404.
 */

const WIZARD_PASSWORD = 'Correct-horse-battery-77';

beforeEach(function () {
    $this->markNotInstalled();
    $this->originalDefault = config('database.default');
    $this->database = ScratchDatabase::create();
    $this->base = ScratchDatabase::base();

    $this->dir = sys_get_temp_dir().'/goproperty-wizard-'.Str::random(10);
    File::ensureDirectoryExists($this->dir.'/sessions');
    File::ensureDirectoryExists($this->dir.'/bucket');
    $this->env = new EnvironmentFile($this->dir.'/.env', base_path('.env.example'));
    $this->app->instance(EnvironmentFile::class, $this->env);
    $this->app->instance(ServerEnvironment::class, FakeServer::make());

    config([
        'installer.session_files' => $this->dir.'/sessions',
        // As in production: the marker is read from the database being installed.
        'installer.marker_connection' => null,
        // Small batches, so the progress bar takes several requests.
        'installer.migrations_per_batch' => 4,
    ]);

    $this->optimizer = new class implements ApplicationOptimizer
    {
        public int $calls = 0;

        public function cache(): void
        {
            $this->calls++;
        }
    };
    $this->app->instance(ApplicationOptimizer::class, $this->optimizer);

    // The bucket is a local folder; the probe's write, read and delete are real.
    $this->bucketConfig = null;
    $this->app->instance(StorageProbe::class, new StorageProbe(app(FilesystemManager::class), function (array $config): Filesystem {
        $this->bucketConfig = $config;

        return Storage::build(['driver' => 'local', 'root' => $this->dir.'/bucket', 'throw' => true]);
    }));

    $this->breached = '';
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->breached)]);

    $this->browser = new InstallerBrowser($this, $this->app);
    $this->smtp = null;
});

afterEach(function () {
    $this->smtp?->stop();
    config(['database.default' => $this->originalDefault]);
    DB::purge(InstallationSteps::CONNECTION);
    ScratchDatabase::dropAll();
    File::deleteDirectory($this->dir);
});

/** Unlocks with the file check and lands on the server check. */
function wizardUnlock(object $test): TestResponse
{
    $test->browser->get('/install')->assertOk();
    preg_match('/id="unlock-phrase">([^<]+)</', $test->browser->page(), $match);
    File::ensureDirectoryExists(dirname(config('installer.paths.unlock_file')));
    file_put_contents(config('installer.paths.unlock_file'), html_entity_decode($match[1])."\n");

    return $test->browser->submit('/install/unlock/file')->assertOk()->assertSee('Server check');
}

/** @return array<string, string> */
function wizardDatabaseFields(object $test, array $overrides = []): array
{
    return array_merge([
        'action' => 'save',
        'site_url' => 'https://goproperty.ng',
        'environment' => 'production',
        'db_driver' => $test->base['driver'],
        'db_host' => (string) $test->base['host'],
        'db_port' => (string) $test->base['port'],
        'db_name' => $test->database,
        'db_user' => (string) $test->base['username'],
        'db_password' => (string) $test->base['password'],
    ], $overrides);
}

/** Posts batches until the database is built; returns the requests made. */
function wizardBuild(object $test): int
{
    $requests = 0;

    do {
        $response = $test->browser->post('/install/database')->assertStatus(303);
        $requests++;
        $location = (string) $response->headers->get('Location');

        if (str_ends_with($location, '/install/database')) {
            $test->browser->get('/install/database')->assertOk()->assertSee('database changes done');
        }
    } while (str_ends_with($location, '/install/database') && $requests < 100);

    expect($location)->toEndWith('/install/services');

    return $requests;
}

function startSmtpSink(object $test): int
{
    $port = random_int(30000, 40000);
    $test->mailbox = $test->dir.'/mailbox.txt';
    $test->smtp = Process::path(base_path())->start([PHP_BINARY, 'scripts/release/smtp-sink.php', (string) $port, $test->mailbox]);

    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if ($socket !== false) {
            fclose($socket);

            return $port;
        }

        usleep(100_000);
    }

    throw new RuntimeException('The SMTP sink did not start.');
}

/** From the super-admin form to the finish step, enrolling TOTP. */
function wizardSuperAdmin(object $test, string $email = 'Ada@GoProperty.ng'): array
{
    $test->browser->submit('/install/admin', [
        'name' => 'Ada Obi', 'email' => $email, 'password' => WIZARD_PASSWORD, 'password_confirmation' => WIZARD_PASSWORD,
    ])->assertOk()->assertSee('Set up your authenticator app')->assertSee('<img src="data:image/', false);

    preg_match('/data-key="([A-Z2-7]+)"/', $test->browser->page(), $match);
    $secret = $match[1];

    $test->browser->submit('/install/admin/two-factor', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()->assertSee('Save your recovery codes')->assertSee("I've saved my recovery codes", false);

    preg_match_all('/<li>([A-Za-z0-9]{10}-[A-Za-z0-9]{10})<\/li>/', $test->browser->page(), $codes);

    $test->browser->submit('/install/admin/recovery-codes', ['saved' => '1'])->assertOk()->assertSee('Finish the installation');

    return [$secret, $codes[1]];
}

it('installs GoProperty over HTTP, the same as the command line', function () {
    wizardUnlock($this);

    // Server check, detailed.
    $this->browser->submit('/install/server')->assertOk()->assertSee('Site &amp; database', false);

    // Site & database: test, then save.
    $this->browser->submit('/install/site', wizardDatabaseFields($this, ['action' => 'test']))
        ->assertOk()->assertSee('Connected. The database is empty.');

    expect($this->env->get('DB_DATABASE'))->not->toBe($this->database);

    $this->browser->submit('/install/site', wizardDatabaseFields($this))->assertOk()->assertSee('Build the database');

    // Batched migrations with a progress bar.
    $this->browser->get('/install/database')->assertSee('0 of ', false)->assertSee('<progress', false);
    expect(wizardBuild($this))->toBeGreaterThan(2);

    // Email: a real test email over SMTP. Storage: write, read, delete.
    $port = startSmtpSink($this);
    $this->browser->submit('/install/services/mail', [
        'action' => 'test', 'mail_provider' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => (string) $port,
        'mail_username' => '', 'mail_from' => 'no-reply@goproperty.ng', 'mail_test' => 'ada@goproperty.ng',
    ])->assertOk()->assertSee('Test email sent to ada@goproperty.ng');

    expect((string) file_get_contents($this->mailbox))->toContain('To: ada@goproperty.ng')->toContain('test email');

    $this->browser->submit('/install/services/storage', [
        'action' => 'test', 'storage_endpoint' => 'https://example.r2.cloudflarestorage.com', 'storage_region' => 'auto',
        'storage_bucket' => 'gp-files', 'storage_key' => 'AKIAEXAMPLE1234', 'storage_secret' => 'very-secret/key+1',
    ])->assertOk()->assertSee('First super-admin');

    expect($this->bucketConfig['bucket'])->toBe('gp-files')
        ->and(File::files($this->dir.'/bucket'))->toBe([]);

    // First super-admin with 2FA enrolled in the wizard.
    [$secret, $codes] = wizardSuperAdmin($this);

    expect($codes)->toHaveCount(8);

    // Finish.
    $finish = $this->browser->post('/install/finish')->assertOk();
    $adminPath = (string) $this->env->get('ADMIN_PATH');
    $content = (string) $finish->getContent();

    $finish->assertSee('GoProperty is installed')
        ->assertSee('https://goproperty.ng/'.$adminPath)
        ->assertSee('Bookmark this — it isn\'t linked anywhere.', false)
        ->assertSee('artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1', false)
        ->assertSee('Waiting for the first scheduler run…')
        ->assertSee('/'.$adminPath.'/system/heartbeat', false)
        ->assertSee('Download the installation record')
        ->assertSee('Turnstile keys')
        ->assertSee('Debug pages are off');

    preg_match('/id="record" download="goproperty-installation-record.txt" href="data:text\/plain;charset=utf-8;base64,([^"]+)"/', $content, $record);
    $recordText = base64_decode($record[1]);

    expect($recordText)->toContain('APP_KEY:        '.$this->env->get('APP_KEY'))
        ->toContain('Console:        https://goproperty.ng/'.$adminPath)
        ->toContain('Database:       '.$this->database)
        ->not->toContain(WIZARD_PASSWORD)
        ->not->toContain('very-secret')
        ->not->toContain('password');

    // Same result as the command-line install.
    $env = $this->env;
    $target = DB::connection($this->database);
    $staff = PlatformStaff::on($this->database)->sole();
    $markers = app(InstallationMarkers::class);

    expect(ApplicationKey::usable($env->get('APP_KEY'), 'AES-256-CBC'))->toBeTrue()
        ->and($env->get('APP_ENV'))->toBe('production')
        ->and($env->get('APP_DEBUG'))->toBe('false')
        ->and($env->get('APP_URL'))->toBe('https://goproperty.ng')
        ->and($env->get('APP_TIMEZONE'))->toBe('Africa/Lagos')
        ->and($env->get('DB_DATABASE'))->toBe($this->database)
        ->and($env->get('HOSTING_PROFILE'))->toBe('shared')
        ->and($env->get('SESSION_DRIVER'))->toBe('database')
        ->and($env->get('CACHE_STORE'))->toBe('database')
        ->and($env->get('MAIL_HOST'))->toBe('127.0.0.1')
        ->and($env->get('MAIL_FROM_ADDRESS'))->toBe('no-reply@goproperty.ng')
        ->and($env->get('AWS_BUCKET'))->toBe('gp-files')
        ->and($env->get('AWS_SECRET_ACCESS_KEY'))->toBe('very-secret/key+1')
        ->and($env->get('AWS_ENDPOINT'))->toBe('https://example.r2.cloudflarestorage.com')
        ->and(AdminPath::isUnique($adminPath))->toBeTrue()
        ->and($staff->email)->toBe('ada@goproperty.ng')
        ->and(Hash::check(WIZARD_PASSWORD, $staff->password))->toBeTrue()
        ->and($staff->two_factor_secret)->toBe($secret)
        ->and($staff->two_factor_confirmed_at)->not->toBeNull()
        ->and($staff->getAppAuthenticationRecoveryCodes())->toHaveCount(8)
        ->and(Hash::check($codes[0], $staff->getAppAuthenticationRecoveryCodes()[0]))->toBeTrue()
        ->and($target->table('installation_state')->value('status'))->toBe('finalized')
        ->and($markers->lockExists())->toBeTrue()
        ->and($markers->checkpoint()->read()->stage)->toBe(InstallStage::Finalized)
        ->and($this->optimizer->calls)->toBe(1)
        ->and(File::exists(config('installer.paths.grant')))->toBeFalse()
        ->and(File::exists(config('installer.paths.unlock_challenge')))->toBeFalse()
        // The web install leaves no record with APP_KEY on the server.
        ->and(File::exists(config('installer.paths.record')))->toBeFalse();

    $audit = $target->table('activity_log')->where('log_name', 'installer')->sole();

    expect($audit->event)->toBe('installed')
        ->and((int) $audit->causer_id)->toBe($staff->id)
        ->and($audit->ip)->toBe('127.0.0.1')
        ->and(json_decode($audit->properties, true))->toEqual(['version' => 'dev', 'via' => 'web']);

    // P01-07: the wizard's first staff account is the super-admin, as on the command line.
    $superAdmin = $target->table('roles')->where('name', 'super-admin')->where('guard_name', 'staff')->whereNull('organization_id')->value('id');
    expect($superAdmin)->not->toBeNull()
        ->and($target->table('model_has_roles')->where('model_type', PlatformStaff::class)->where('model_id', $staff->id)->pluck('role_id')->all())->toBe([$superAdmin]);

    // The schema equals a plain migrate.
    $reference = ScratchDatabase::create();
    Artisan::call('migrate', ['--database' => $reference, '--force' => true]);
    $snapshots = app(SchemaSnapshot::class);

    expect($snapshots->take(DB::connection($this->database)))->toBe($snapshots->take(DB::connection($reference)));

    // Closed for good: every installer page is a 404, with no cookie.
    foreach (['/install', '/install/server', '/install/resume', '/install/finish'] as $path) {
        $response = $this->browser->get($path)->assertNotFound();
        expect($response->headers->getCookies())->toBe([]);
    }

    $this->browser->post('/install/finish')->assertNotFound();
    $this->browser->post('/install/unlock/file')->assertNotFound();
});

it('hands the owner a signed-in console session that shows the cron heartbeat', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    wizardBuild($this);
    $this->browser->submit('/install/services/mail', ['action' => 'skip']);
    $this->browser->submit('/install/services/storage', ['action' => 'skip']);
    wizardSuperAdmin($this);

    $finish = $this->browser->post('/install/finish')->assertOk()
        ->assertSee('You are signed in to the console.')
        ->assertSee('Set up outgoing email')
        ->assertSee('Set up file storage');

    $cookie = collect($finish->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'gp_staff_session');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/')
        ->and(DB::connection($this->database)->table('sessions')->count())->toBe(1)
        ->and(DB::connection($this->database)->table('authentication_logs')->where('event', 'login_success')->count())->toBe(1);

    // The next request reads the finished site's settings (database sessions).
    config(['session.driver' => 'database', 'session.encrypt' => false, 'session.files' => storage_path('framework/sessions')]);
    $console = '/'.config('app.admin_path');

    $this->browser->get($console)->assertOk();

    $this->browser->get($console.'/system/heartbeat')->assertOk()->assertJson(['scheduler' => ['at' => null, 'fresh' => false]]);
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->browser->get($console.'/system/heartbeat')->assertOk()->assertJson(['scheduler' => ['fresh' => true]]);
});

it('carries on after an interruption: a new session, a lost tab, the same database', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    $this->browser->post('/install/database')->assertRedirect('/install/database');
    $this->browser->post('/install/database')->assertRedirect('/install/database');
    $done = DB::connection($this->database)->table('migrations')->count();

    expect($done)->toBe(8);

    // The tab is closed and the grant lost: unlock again in another browser.
    $this->browser = new InstallerBrowser($this, $this->app);
    wizardUnlock($this);
    $this->browser->follow($this->browser->get('/install/resume'))->assertOk()->assertSee('Build the database')->assertSee("{$done} of ", false);

    wizardBuild($this);

    expect(DB::connection($this->database)->table('migrations')->count())->toBeGreaterThan($done);
});

it('refuses a database with tables that are not GoProperty\'s, writing nothing', function () {
    DB::connection($this->database)->statement('create table wp_posts (id int)');
    wizardUnlock($this);
    $this->browser->submit('/install/server');

    $this->browser->submit('/install/site', wizardDatabaseFields($this))
        ->assertOk()->assertSee('already has tables that are not GoProperty')->assertSee('wp_posts');

    expect($this->env->get('DB_DATABASE'))->not->toBe($this->database)
        ->and(app(InstallationMarkers::class)->checkpoint()->read()->exists)->toBeFalse();
});

it('explains a refused password and never shows it again', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');

    $page = $this->browser->submit('/install/site', wizardDatabaseFields($this, ['db_password' => 'wrong-Secret-9']))
        ->assertOk()->assertSee('The username or password was refused.');

    expect((string) $page->getContent())->not->toContain('wrong-Secret-9')
        ->and($page->getContent())->toContain('value="'.$this->database.'"');
});

it('stops when the database settings change mid-install', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));

    // Someone edits .env to another (empty) database between requests.
    $other = ScratchDatabase::create();
    config(['database.connections.'.InstallationSteps::CONNECTION.'.database' => $other]);
    DB::purge(InstallationSteps::CONNECTION);

    $this->browser->get('/install/database')->assertStatus(409)->assertSee('Install stopped')->assertSee('install-recovery.md');
    $this->browser->post('/install/database')->assertStatus(409);
});

it('offers to empty the database after a failed migration', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    wizardBuild($this);

    // A table a later migration needs to create already exists, half-made.
    // The newest migration that creates a table (a data-only migration,
    // such as P02-05's branding move, runs again without failing). Module
    // migrations (Modules/*/database/migrations) run in the same batch.
    $migrationFile = fn (string $name): string => (glob(database_path("migrations/{$name}.php")) ?: glob(base_path("Modules/*/database/migrations/{$name}.php")) ?: [''])[0];
    $migration = DB::connection($this->database)->table('migrations')->orderByDesc('id')->pluck('migration')
        ->first(fn (string $name): bool => str_contains((string) file_get_contents($migrationFile($name)), 'Schema::create('));
    DB::connection($this->database)->table('migrations')->where('migration', $migration)->delete();

    $this->browser->post('/install/database')->assertRedirect('/install/database');
    $this->browser->get('/install/database')->assertOk()
        ->assertSee("Migration {$migration} failed")
        ->assertSee('Empty the database and start this step again');

    $this->browser->submit('/install/database/reset', ['confirm_database' => 'wrong'])->assertSee('Type the database name exactly to confirm.');
    $this->browser->submit('/install/database/reset', ['confirm_database' => $this->database])->assertSee('GoProperty tables dropped');

    expect(DB::connection($this->database)->getSchemaBuilder()->getTableListing($this->database, false))->toBe([]);

    wizardBuild($this);
});

it('asks for the super-admin rules and refuses another account once one exists', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    wizardBuild($this);
    $this->browser->submit('/install/services/mail', ['action' => 'skip']);
    $this->browser->submit('/install/services/storage', ['action' => 'skip']);

    $this->browser->submit('/install/admin', ['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'short-pass', 'password_confirmation' => 'short-pass'])
        ->assertSee('must be at least 12 characters');
    $this->browser->submit('/install/admin', ['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => WIZARD_PASSWORD, 'password_confirmation' => 'different'])
        ->assertSee('The two passwords differ.');

    $this->breached = strtoupper(substr(sha1(WIZARD_PASSWORD), 5)).":42\r\n";
    $this->browser->submit('/install/admin', ['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => WIZARD_PASSWORD, 'password_confirmation' => WIZARD_PASSWORD])
        ->assertSee('has appeared in a data leak');
    $this->breached = '';

    // Created, then the tab is lost before the authenticator is confirmed.
    $this->browser->submit('/install/admin', ['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => WIZARD_PASSWORD, 'password_confirmation' => WIZARD_PASSWORD])
        ->assertSee('Set up your authenticator app');

    // A wrong code is refused.
    $this->browser->submit('/install/admin/two-factor', ['code' => '000000'])->assertSee('That code is not right.');

    $this->browser = new InstallerBrowser($this, $this->app);
    wizardUnlock($this);
    $this->browser->follow($this->browser->get('/install/resume'))->assertSee('First super-admin');

    $this->browser->submit('/install/admin', ['name' => 'Eve', 'email' => 'eve@goproperty.ng', 'password' => 'another-Long-pass-77', 'password_confirmation' => 'another-Long-pass-77'])
        ->assertSee('A staff account already exists.');

    // The same email and password resume, with a new authenticator secret.
    wizardSuperAdmin($this, 'ada@goproperty.ng');

    expect(PlatformStaff::on($this->database)->count())->toBe(1);
});

it('requires the recovery codes to be saved before finishing', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    wizardBuild($this);
    $this->browser->submit('/install/services/mail', ['action' => 'skip']);
    $this->browser->submit('/install/services/storage', ['action' => 'skip']);
    $this->browser->submit('/install/admin', ['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => WIZARD_PASSWORD, 'password_confirmation' => WIZARD_PASSWORD]);
    preg_match('/data-key="([A-Z2-7]+)"/', $this->browser->page(), $match);
    $this->browser->submit('/install/admin/two-factor', ['code' => app(Google2FA::class)->getCurrentOtp($match[1])]);

    $this->browser->get('/install/finish')->assertRedirect('/install/admin');
    $this->browser->submit('/install/admin/recovery-codes', [])->assertSee('Tick the box once your recovery codes are stored somewhere safe.');
    $this->browser->post('/install/finish')->assertRedirect('/install/admin');

    expect(app(InstallationMarkers::class)->lockExists())->toBeFalse();
});

it('reports a failed test email and a refused bucket', function () {
    wizardUnlock($this);
    $this->browser->submit('/install/server');
    $this->browser->submit('/install/site', wizardDatabaseFields($this));
    wizardBuild($this);

    $this->browser->submit('/install/services/mail', [
        'action' => 'test', 'mail_provider' => 'smtp', 'mail_host' => '127.0.0.1', 'mail_port' => '1',
        'mail_from' => 'no-reply@goproperty.ng', 'mail_test' => 'ada@goproperty.ng',
    ])->assertOk()->assertSee('The test email could not be sent');

    $this->app->instance(StorageProbe::class, new StorageProbe(app(FilesystemManager::class), function (): Filesystem {
        throw new RuntimeException('Error executing "PutObject": InvalidAccessKeyId AKIAEXAMPLE1234 very-secret/key+1');
    }));

    $page = $this->browser->submit('/install/services/storage', [
        'action' => 'test', 'storage_region' => 'eu-west-1', 'storage_bucket' => 'gp-files',
        'storage_key' => 'AKIAEXAMPLE1234', 'storage_secret' => 'very-secret/key+1',
    ])->assertOk()->assertSee('The access key or secret was refused.');

    expect((string) $page->getContent())->not->toContain('very-secret')
        ->and($this->env->get('AWS_BUCKET'))->toBe('')
        ->and(app(InstallationMarkers::class)->checkpoint()->read()->stage)->toBe(InstallStage::Migrating);

    // Neither is settled, so the super-admin step stays shut.
    $this->browser->get('/install/admin')->assertRedirect('/install/services');
});
