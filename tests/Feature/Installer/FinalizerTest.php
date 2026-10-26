<?php

declare(strict_types=1);

use App\Installer\Database\DatabaseSettings;
use App\Installer\EnvironmentFile;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\Finishing\Finalizer;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Installer\SiteState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * P00-05: finishing closes the installer for good, in the order of
 * INSTALLER.md § 5 ("Finish").
 */

beforeEach(function () {
    $this->markNotInstalled();

    $this->markers = app(InstallationMarkers::class);
    $this->markers->checkpoint()->write(
        InstallStage::OwnerReady,
        DatabaseSettings::fromConnection(config('database.connections.'.config('database.default'))),
    );

    $this->dir = sys_get_temp_dir().'/goproperty-finish-'.Str::random(10);
    File::ensureDirectoryExists($this->dir);
    file_put_contents($this->dir.'/.env', "APP_KEY=base64:x\nADMIN_PATH=gp-control\nSESSION_DRIVER=file\nCACHE_STORE=file\n");
    $this->env = new EnvironmentFile($this->dir.'/.env');

    $this->optimizer = new class implements ApplicationOptimizer
    {
        public int $calls = 0;

        public ?Throwable $fail = null;

        public function cache(): void
        {
            $this->calls++;

            if ($this->fail !== null) {
                throw $this->fail;
            }
        }
    };
    $this->app->instance(ApplicationOptimizer::class, $this->optimizer);

    foreach (Finalizer::SECRETS as $name) {
        File::ensureDirectoryExists(dirname($this->markers->path($name)));
        file_put_contents($this->markers->path($name), 'secret');
    }
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('finishes the install', function () {
    expect($this->markers->installerOpen())->toBeTrue();

    $result = app(Finalizer::class)->finalize($this->env, '1.4.0');

    expect($result->adminPath)->toMatch('/^gp-control-[a-z0-9]{6}$/')
        ->and($result->warnings)->toBe([])
        ->and($this->env->get('ADMIN_PATH'))->toBe($result->adminPath)
        ->and($this->env->get('SESSION_DRIVER'))->toBe('database')
        ->and($this->env->get('CACHE_STORE'))->toBe('database')
        ->and($this->env->get('APP_KEY'))->toBe('base64:x')
        ->and($this->markers->lockExists())->toBeTrue()
        ->and(json_decode((string) file_get_contents(config('installer.paths.lock')), true)['version'])->toBe('1.4.0')
        ->and(app(InstallationRecord::class)->stage(DB::connection()))->toBe(InstallStage::Finalized)
        ->and(DB::table('installation_state')->value('version'))->toBe('1.4.0')
        ->and(DB::table('installation_state')->value('finalized_at'))->not->toBeNull()
        ->and($this->markers->checkpoint()->read()->stage)->toBe(InstallStage::Finalized)
        ->and($this->optimizer->calls)->toBe(1)
        ->and($this->markers->installerOpen())->toBeFalse()
        ->and($this->markers->siteState())->toBe(SiteState::Open);

    foreach (Finalizer::SECRETS as $name) {
        expect(file_exists($this->markers->path($name)))->toBeFalse("{$name} was not deleted.");
    }

    $this->get('/install')->assertNotFound();
    $this->get('/')->assertOk();
});

it('logs the finish without the console path', function () {
    $result = app(Finalizer::class)->finalize($this->env, '1.4.0');

    $log = (string) file_get_contents(config('logging.channels.install.path'));

    expect($log)->toContain('Installation finalized')
        ->toContain('1.4.0')
        ->not->toContain($result->adminPath);
});

it('keeps a unique console path when run again', function () {
    $first = app(Finalizer::class)->finalize($this->env, '1.4.0');
    $second = app(Finalizer::class)->finalize($this->env, '1.4.0');

    expect($second->adminPath)->toBe($first->adminPath);
});

it('replaces an invalid or default console path', function (string $current) {
    file_put_contents($this->dir.'/.env', "ADMIN_PATH={$current}\n");

    expect(app(Finalizer::class)->finalize($this->env, '1.4.0')->adminPath)->toMatch('/^gp-control-[a-z0-9]{6}$/');
})->with(['gp-control', 'admin', 'UPPER-case-path', 'short']);

it('closes the installer before anything else, so a failure stays closed', function () {
    // .env cannot be written: step 2 fails after step 1.
    $readOnly = $this->dir.'/read-only';
    File::ensureDirectoryExists($readOnly);
    file_put_contents($readOnly.'/.env', "ADMIN_PATH=gp-control\n");
    chmod($readOnly, 0555);

    try {
        expect(fn () => app(Finalizer::class)->finalize(new EnvironmentFile($readOnly.'/.env'), '1.4.0'))
            ->toThrow(RuntimeException::class);
    } finally {
        chmod($readOnly, 0755);
    }

    expect($this->markers->checkpoint()->read()->stage)->toBe(InstallStage::CleanupPending)
        ->and($this->markers->installerOpen())->toBeFalse()
        ->and($this->markers->lockExists())->toBeFalse()
        ->and($this->markers->siteState())->toBe(SiteState::NotInstalled);
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores folder permissions');

it('finishes even when the caches cannot be built', function () {
    $this->optimizer->fail = new RuntimeException('php artisan route:cache failed.');

    $result = app(Finalizer::class)->finalize($this->env, '1.4.0');

    expect($result->warnings)->toBe(['The caches could not be built (php artisan route:cache failed.). Run php artisan optimize later.'])
        ->and($this->markers->siteState())->toBe(SiteState::Open);
});

it('writes its own checkpoint when the command-line install has none', function () {
    $this->markers->checkpoint()->delete();

    app(Finalizer::class)->finalize($this->env, '1.4.0');

    expect($this->markers->checkpoint()->read()->stage)->toBe(InstallStage::Finalized);
});
