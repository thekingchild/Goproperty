<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureInstallerOpen;
use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Installer\SetupToken;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/*
 * P01-09 acceptance: after finish /install and every wizard route answer
 * 404, and no HTTP route can reset or re-run the installation
 * (INSTALLER.md § 3, § 9). Recovery is from the command line or File
 * Manager only; these tests fail if a web route for it is ever added.
 */

/** @return list<Route> */
function installerRoutes(): array
{
    return array_values(array_filter(
        Router::getRoutes()->getRoutes(),
        fn (Route $route): bool => $route->uri() === 'install' || str_starts_with($route->uri(), 'install/'),
    ));
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/goproperty-closed-'.Str::random(10);
    File::ensureDirectoryExists($this->dir);
    $this->env = new EnvironmentFile($this->dir.'/.env', base_path('.env.example'));
    $this->app->instance(EnvironmentFile::class, $this->env);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('has the wizard under /install, every route behind EnsureInstallerOpen first', function () {
    $routes = installerRoutes();
    $uris = array_map(fn (Route $route): string => $route->uri(), $routes);

    expect($uris)->toContain('install', 'install/unlock/token', 'install/unlock/file', 'install/server', 'install/site',
        'install/database', 'install/database/reset', 'install/services', 'install/admin', 'install/finish');

    foreach ($routes as $route) {
        expect($route->gatherMiddleware()[0] ?? null)->toBe(EnsureInstallerOpen::class, "{$route->uri()} must check the markers first");
    }
});

it('answers 404 on every installer route and method once installed, without a session or any change', function () {
    // The site is installed (TestCase), and someone holds valid unlock proofs.
    $token = app(SetupToken::class)->issue();
    File::ensureDirectoryExists(dirname(config('installer.paths.unlock_file')));
    file_put_contents(config('installer.paths.unlock_file'), 'GP-UNLOCK AAAA-BBBB-CCCC');
    $lock = File::get(config('installer.paths.lock'));
    $requests = 0;

    foreach (installerRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->call($method, '/'.$route->uri(), ['token' => $token, 'confirm_database' => DB::connection()->getDatabaseName()], [], [], [
                'HTTP_ORIGIN' => 'http://localhost',
            ]);

            $response->assertNotFound();
            expect($response->headers->getCookies())->toBe([], "{$method} /{$route->uri()} set a cookie");
            $requests++;
        }
    }

    expect($requests)->toBeGreaterThanOrEqual(18)
        ->and($this->env->exists())->toBeFalse()
        ->and(File::get(config('installer.paths.lock')))->toBe($lock)
        ->and(File::exists(config('installer.paths.grant')))->toBeFalse()
        ->and(DB::table(InstallationRecord::TABLE)->value('status'))->toBe('finalized');
});

it('keeps every installer route closed when any one marker says finished', function (string $marker) {
    $this->markNotInstalled();
    $markers = app(InstallationMarkers::class);

    match ($marker) {
        'lock file' => $markers->writeLock('1.0.0'),
        'database row' => app(InstallationRecord::class)->record(DB::connection(), InstallStage::Finalized, '1.0.0'),
        'cleanup checkpoint' => $markers->checkpoint()->write(InstallStage::CleanupPending, DatabaseSettings::fromConnection(config('database.connections.'.config('database.default')))),
    };

    foreach (installerRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $this->call($method, '/'.$route->uri(), [], [], [], ['HTTP_ORIGIN' => 'http://localhost'])->assertNotFound();
        }
    }
})->with(['lock file', 'database row', 'cleanup checkpoint']);

it('has no route outside /install that runs installer code', function () {
    $forbidden = [
        'App\Installer\InstallationSteps',
        'App\Installer\Finishing\Finalizer',
        'App\Installer\Migrations\FreshDatabaseReset',
        'App\Installer\Migrations\BatchedMigrator',
        'App\Installer\SetupToken',
        'App\Installer\Web\UnlockChallenge',
        'App\Installer\Web\InstallerGrant',
        'App\Installer\Web\ConsoleHandover',
    ];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        if ($route->uri() === 'install' || str_starts_with($route->uri(), 'install/')) {
            continue;
        }

        $uses = $route->getAction('uses');
        $types = [];

        if ($uses instanceof Closure) {
            $types = array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), (new ReflectionFunction($uses))->getParameters());
        } elseif (is_string($uses) && str_contains($uses, '@')) {
            [$class, $method] = explode('@', $uses);

            expect($class)->not->toStartWith('App\Http\Controllers\Install\\', "/{$route->uri()} uses an installer controller");

            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);
                $parameters = [
                    ...($reflection->getConstructor()?->getParameters() ?? []),
                    ...($reflection->hasMethod($method) ? $reflection->getMethod($method)->getParameters() : []),
                ];
                $types = array_map(fn (ReflectionParameter $parameter): string => (string) $parameter->getType(), $parameters);
            }
        }

        foreach ($types as $type) {
            expect(in_array(ltrim($type, '?'), $forbidden, true))->toBeFalse("/{$route->uri()} injects {$type}");
        }
    }
});

it('has no web-reachable code that installs, finishes, resets or recovers', function () {
    // Code that serves HTTP requests, apart from the installer's own
    // controllers and middleware (all behind EnsureInstallerOpen).
    $allowed = [
        app_path('Http/Controllers/Install'),
        app_path('Http/Middleware/Installer'),
        app_path('Http/Middleware/EnsureInstallerOpen.php'),
        app_path('Http/Middleware/EnsureSiteInstalled.php'),
        app_path('Http/Middleware/AwaitApplicationKey.php'),
        resource_path('views/installer'),
    ];
    $tokens = [
        'InstallationSteps', 'Finalizer', 'FreshDatabaseReset', 'BatchedMigrator', 'SetupToken', 'UnlockChallenge',
        'InstallerGrant', 'ConsoleHandover', 'writeLock(', 'goproperty:install', 'goproperty:finish-install',
        'goproperty:setup-token', 'migrate:fresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', "'migrate'",
    ];
    $files = collect([
        ...File::allFiles(app_path('Http')),
        ...File::allFiles(app_path('Livewire')),
        ...File::allFiles(app_path('Platform')),
        ...File::allFiles(resource_path('views')),
        ...array_filter(File::files(base_path('routes')), fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['install.php', 'console.php'], true)),
    ])->reject(fn (SplFileInfo $file): bool => collect($allowed)->contains(fn (string $path): bool => str_starts_with($file->getPathname(), $path)));

    expect($files->count())->toBeGreaterThan(20);

    foreach ($files as $file) {
        $contents = $file->getContents();

        foreach ($tokens as $token) {
            expect(str_contains($contents, $token))->toBeFalse("{$file->getRelativePathname()} mentions {$token}");
        }
    }
});
