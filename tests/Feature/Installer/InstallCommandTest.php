<?php

declare(strict_types=1);

use App\Installer\ApplicationKey;
use App\Installer\Database\DatabaseSettings;
use App\Installer\Database\SchemaSnapshot;
use App\Installer\EnvironmentFile;
use App\Installer\Finishing\AdminPath;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\InstallationException;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationSteps;
use App\Installer\InstallStage;
use App\Installer\Migrations\BatchedMigrator;
use App\Installer\Requirements\ServerEnvironment;
use App\Installer\SiteSettings;
use App\Models\PlatformStaff;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\FakeServer;
use Tests\Support\ScratchDatabase;

/*
 * P00-06: `php artisan goproperty:install` (INSTALLER.md § 7) into a real
 * throwaway database. The schema it builds must match a plain `migrate`.
 */

const ADMIN_PASSWORD = 'correct-Horse-battery-9';

beforeEach(function () {
    $this->markNotInstalled();
    $this->originalDefault = config('database.default');
    $this->database = ScratchDatabase::create();
    $this->base = ScratchDatabase::base();

    $this->dir = sys_get_temp_dir().'/goproperty-cli-'.Str::random(10);
    File::ensureDirectoryExists($this->dir);
    $this->env = new EnvironmentFile($this->dir.'/.env', base_path('.env.example'));
    $this->app->instance(EnvironmentFile::class, $this->env);

    file_put_contents($this->dir.'/db-password', ((string) $this->base['password'])."\n");
    file_put_contents($this->dir.'/admin-password', ADMIN_PASSWORD."\n");
    chmod($this->dir.'/db-password', 0600);
    chmod($this->dir.'/admin-password', 0600);

    $this->optimizer = new class implements ApplicationOptimizer
    {
        public int $calls = 0;

        public function cache(): void
        {
            $this->calls++;
        }
    };
    $this->app->instance(ApplicationOptimizer::class, $this->optimizer);
    $this->app->instance(ServerEnvironment::class, FakeServer::make());

    // The breach check answers from $this->breached (no match by default).
    $this->breached = '';
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->breached)]);
});

afterEach(function () {
    config(['database.default' => $this->originalDefault]);
    DB::purge(InstallationSteps::CONNECTION);
    ScratchDatabase::dropAll();
    File::deleteDirectory($this->dir);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function installOptions(object $test, array $overrides = []): array
{

    return array_filter(array_merge([
        '--no-interaction' => true,
        '--env' => 'production',
        '--url' => 'https://goproperty.ng',
        '--db-driver' => $test->base['driver'],
        '--db-host' => (string) $test->base['host'],
        '--db-port' => (string) $test->base['port'],
        '--db-name' => $test->database,
        '--db-user' => (string) $test->base['username'],
        '--db-password-file' => $test->dir.'/db-password',
        '--admin-name' => 'Ada Obi',
        '--admin-email' => 'Ada@GoProperty.ng',
        '--admin-password-file' => $test->dir.'/admin-password',
    ], $overrides), fn ($value) => $value !== null);
}

it('installs GoProperty from the command line', function () {
    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('Server check')
        ->expectsOutputToContain('Building the database')
        ->expectsOutputToContain('GoProperty is installed.')
        ->expectsOutputToContain('https://goproperty.ng/gp-control-')
        ->expectsOutputToContain('artisan schedule:run >> /dev/null 2>&1')
        ->assertSuccessful();

    $env = $this->env;
    $target = DB::connection($this->database);

    expect(ApplicationKey::usable($env->get('APP_KEY'), 'AES-256-CBC'))->toBeTrue()
        ->and($env->get('APP_ENV'))->toBe('production')
        ->and($env->get('APP_DEBUG'))->toBe('false')
        ->and($env->get('APP_URL'))->toBe('https://goproperty.ng')
        ->and($env->get('APP_TIMEZONE'))->toBe('Africa/Lagos')
        ->and($env->get('DB_DATABASE'))->toBe($this->database)
        ->and($env->get('DB_PASSWORD'))->toBe((string) $this->base['password'])
        ->and($env->get('HOSTING_PROFILE'))->toBe('shared')
        ->and($env->get('SESSION_DRIVER'))->toBe('database')
        ->and($env->get('CACHE_STORE'))->toBe('database')
        ->and(AdminPath::isUnique((string) $env->get('ADMIN_PATH')))->toBeTrue();

    $staff = PlatformStaff::on($this->database)->sole();

    expect($staff->email)->toBe('ada@goproperty.ng')
        ->and(Hash::check(ADMIN_PASSWORD, $staff->password))->toBeTrue()
        ->and($staff->two_factor_secret)->toBeNull()
        ->and($target->table('installation_state')->value('status'))->toBe('finalized');

    $markers = app(InstallationMarkers::class);
    $record = (string) file_get_contents(config('installer.paths.record'));

    expect($markers->lockExists())->toBeTrue()
        ->and($markers->checkpoint()->read()->stage)->toBe(InstallStage::Finalized)
        ->and($this->optimizer->calls)->toBe(1)
        ->and(substr(sprintf('%o', fileperms(config('installer.paths.record'))), -4))->toBe('0600')
        ->and($record)->toContain('APP_KEY:        '.$env->get('APP_KEY'))
        ->and($record)->toContain('Console:        https://goproperty.ng/'.$env->get('ADMIN_PATH'))
        ->and($record)->not->toContain(ADMIN_PASSWORD)
        ->and($record)->not->toContain('DB_PASSWORD')
        ->and($record)->not->toContain('password');
});

it('builds the same schema as a plain migrate', function () {
    $this->artisan('goproperty:install', installOptions($this))->assertSuccessful();

    $reference = ScratchDatabase::create();
    config(['database.default' => $this->originalDefault]);
    Artisan::call('migrate', ['--database' => $reference, '--force' => true]);

    $snapshots = app(SchemaSnapshot::class);
    $installed = $snapshots->take(DB::connection($this->database));

    expect($installed['tables'])->toHaveKeys(['installation_state', 'platform_staff', 'users', 'migrations'])
        ->and($installed)->toBe($snapshots->take(DB::connection($reference)));
});

it('refuses to run once installed', function () {
    $this->artisan('goproperty:install', installOptions($this))->assertSuccessful();

    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('already installed')
        ->assertFailed();
});

it('continues an interrupted install', function () {
    $steps = app(InstallationSteps::class);
    $steps->ensureApplicationKey($this->env);
    $settings = DatabaseSettings::fromConnection(array_merge($this->base, ['database' => $this->database]));
    $steps->configureSite($this->env, new SiteSettings('https://goproperty.ng', 'production'), $settings);
    app(BatchedMigrator::class)->runBatch(max: 3);
    $key = $this->env->get('APP_KEY');

    $this->artisan('goproperty:install', installOptions($this))->assertSuccessful();

    expect($this->env->get('APP_KEY'))->toBe($key)
        ->and(app(BatchedMigrator::class)->progress()->finished())->toBeTrue();
});

it('refuses other database settings than the ones an install started with', function () {
    $steps = app(InstallationSteps::class);
    $steps->ensureApplicationKey($this->env);
    $steps->configureSite($this->env, new SiteSettings('https://goproperty.ng', 'production'),
        DatabaseSettings::fromConnection(array_merge($this->base, ['database' => $this->database])));
    config(['database.default' => $this->originalDefault]);

    $other = ScratchDatabase::create();

    $this->artisan('goproperty:install', installOptions($this, ['--db-name' => $other]))
        ->expectsOutputToContain('differ from the ones this install started with')
        ->assertFailed();
});

it('resumes with the same super-admin but refuses another', function () {
    $this->artisan('goproperty:install', installOptions($this))->assertSuccessful();
    $steps = app(InstallationSteps::class);

    expect($steps->createSuperAdmin('Ada Obi', 'ada@goproperty.ng', ADMIN_PASSWORD)->email)->toBe('ada@goproperty.ng')
        ->and(fn () => $steps->createSuperAdmin('Eve', 'eve@goproperty.ng', 'another-Long-pass-77'))
        ->toThrow(InstallationException::class, 'A staff account already exists.');
});

it('stops before writing anything when an answer is missing', function (string $option, string $message) {
    $this->artisan('goproperty:install', installOptions($this, [$option => null]))
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->env->exists())->toBeFalse();
})->with([
    ['--db-name', 'Pass --db-name.'],
    ['--db-user', 'Pass --db-user.'],
    ['--url', 'Pass --url.'],
    ['--env', 'Pass --env=production or --env=staging.'],
    ['--admin-email', 'Pass --admin-email.'],
    ['--admin-password-file', 'Pass --admin-password-file (passwords are never accepted as arguments).'],
]);

it('stops before writing anything when an answer is invalid', function (array $overrides, string $message) {
    if (($overrides['--admin-password-file'] ?? null) === '__short__') {
        file_put_contents($this->dir.'/short', 'short-pass');
        $overrides['--admin-password-file'] = $this->dir.'/short';
    }

    $this->artisan('goproperty:install', installOptions($this, $overrides))
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->env->exists())->toBeFalse();
})->with([
    'http address' => [['--url' => 'http://goproperty.ng'], 'must start with https://'],
    'address with a path' => [['--url' => 'https://goproperty.ng/app'], 'no path'],
    'environment' => [['--env' => 'local'], 'production or staging'],
    'driver' => [['--db-driver' => 'pgsql'], 'Choose MySQL or MariaDB.'],
    'port' => [['--db-port' => 'abc'], 'The database port must be a number.'],
    'unreadable password file' => [['--db-password-file' => '/no/such/file'], 'Cannot read the file given to --db-password-file.'],
    'short admin password' => [['--admin-password-file' => '__short__'], 'must be at least 12 characters'],
    'bad admin email' => [['--admin-email' => 'not-an-email'], 'must be a valid email address'],
]);

it('refuses a breached super-admin password', function () {
    $suffix = strtoupper(substr(sha1(ADMIN_PASSWORD), 5));
    $this->breached = "{$suffix}:42\r\n";

    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('has appeared in a data leak')
        ->assertFailed();

    expect($this->env->exists())->toBeFalse();
});

it('stops when the server check fails, with cPanel and Plesk fixes', function () {
    $this->app->instance(ServerEnvironment::class, FakeServer::make(['php' => '8.3.9', 'missing' => ['intl']]));

    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('PHP 8.4 or newer')
        ->expectsOutputToContain('cPanel: cPanel → MultiPHP Manager')
        ->expectsOutputToContain('PHP extension: intl')
        ->expectsOutputToContain('Fix the items above')
        ->assertFailed();

    expect($this->env->exists())->toBeFalse();
});

it('explains a refused database password without writing database settings', function () {
    file_put_contents($this->dir.'/db-password', 'wrong-password');

    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('The username or password was refused.')
        ->assertFailed();

    expect($this->env->get('DB_DATABASE'))->not->toBe($this->database);
});

it('stops when the test email cannot be sent', function () {
    $this->artisan('goproperty:install', installOptions($this, [
        '--mail-host' => '127.0.0.1',
        '--mail-port' => '1',
        '--mail-from' => 'no-reply@goproperty.ng',
        '--mail-test' => 'ada@goproperty.ng',
    ]))
        ->expectsOutputToContain('The test email could not be sent')
        ->assertFailed();

    expect(app(InstallationMarkers::class)->lockExists())->toBeFalse()
        ->and($this->env->get('MAIL_HOST'))->toBe('127.0.0.1');
});

it('asks for the mail settings before testing them', function () {
    $this->artisan('goproperty:install', installOptions($this, ['--mail-test' => 'ada@goproperty.ng']))
        ->expectsOutputToContain('--mail-test needs the email settings')
        ->assertFailed();
});

it('warns about a password file others can read', function () {
    chmod($this->dir.'/admin-password', 0644);

    $this->artisan('goproperty:install', installOptions($this))
        ->expectsOutputToContain('can be read by other users')
        ->assertSuccessful();
});

it('asks the questions it has no options for', function () {
    $this->artisan('goproperty:install', [
        '--db-driver' => $this->base['driver'],
        '--db-host' => (string) $this->base['host'],
        '--db-port' => (string) $this->base['port'],
        '--db-password-file' => $this->dir.'/db-password',
    ])
        ->expectsQuestion('Site address', 'https://goproperty.ng')
        ->expectsQuestion('Is this production or staging?', 'staging')
        ->expectsQuestion('Database name', $this->database)
        ->expectsQuestion('Database user', (string) $this->base['username'])
        ->expectsConfirmation('Set up outgoing email (SMTP) now?', 'no')
        ->expectsQuestion('First super-admin\'s name', 'Ada Obi')
        ->expectsQuestion('Their work email', 'ada@goproperty.ng')
        ->expectsQuestion('Their password (12+ characters)', ADMIN_PASSWORD)
        ->expectsQuestion('Type it again', ADMIN_PASSWORD)
        ->expectsOutputToContain('Email is not set up.')
        ->assertSuccessful();

    expect($this->env->get('APP_ENV'))->toBe('staging');
});

it('notices a schema that differs from a plain migrate', function () {
    config(['database.default' => $this->originalDefault]);
    $other = ScratchDatabase::create();
    Artisan::call('migrate', ['--database' => $this->database, '--force' => true]);
    Artisan::call('migrate', ['--database' => $other, '--force' => true]);
    $snapshots = app(SchemaSnapshot::class);

    expect($snapshots->take(DB::connection($this->database)))->toBe($snapshots->take(DB::connection($other)));

    Schema::connection($other)->table('users', fn ($table) => $table->string('nickname')->nullable());

    expect($snapshots->take(DB::connection($this->database)))->not->toBe($snapshots->take(DB::connection($other)));
});
