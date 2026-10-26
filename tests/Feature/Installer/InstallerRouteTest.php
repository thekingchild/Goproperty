<?php

declare(strict_types=1);

use App\Installer\Database\DatabaseSettings;
use App\Installer\DatabaseMarker;
use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * P00-05 acceptance (second half): any one installation marker that says
 * the install finished keeps /install closed, and closed means 404
 * (INSTALLER.md § 3).
 */

function markers(): InstallationMarkers
{
    return app(InstallationMarkers::class);
}

function databaseSettings(): DatabaseSettings
{
    return DatabaseSettings::fromConnection(config('database.connections.'.config('database.default')));
}

beforeEach(function () {
    $this->markNotInstalled();
});

it('is open on a fresh copy', function () {
    $this->get('/install')->assertOk()->assertSee('Server check');

    expect(markers()->installerOpen())->toBeTrue();
});

it('stays open while the install is in progress', function (InstallStage $stage) {
    markers()->checkpoint()->write($stage, databaseSettings());
    app(InstallationRecord::class)->record(DB::connection(), $stage, 'dev');

    $this->get('/install')->assertOk();
})->with([InstallStage::Configured, InstallStage::Migrating, InstallStage::OwnerReady]);

it('is closed by the lock file alone', function () {
    markers()->writeLock('1.0.0');

    $this->get('/install')->assertNotFound();
});

it('is closed by the database row alone', function () {
    app(InstallationRecord::class)->record(DB::connection(), InstallStage::Finalized, '1.0.0');

    $this->get('/install')->assertNotFound();
});

it('is closed by the checkpoint alone', function (InstallStage $stage) {
    markers()->checkpoint()->write($stage, databaseSettings());

    $this->get('/install')->assertNotFound();
})->with([InstallStage::CleanupPending, InstallStage::Finalized]);

it('is closed by a checkpoint it cannot verify', function (string $contents) {
    $path = config('installer.paths.checkpoint');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, $contents);

    $this->get('/install')->assertNotFound();
})->with([
    'not json' => ['garbage'],
    'unknown stage' => ['{"stage":"done","fingerprint":"x","updated_at":"now","mac":"x"}'],
    'forged stage' => ['{"stage":"configured","fingerprint":"x","updated_at":"now","mac":"0000"}'],
]);

it('is closed by a checkpoint signed with another key', function () {
    markers()->checkpoint()->write(InstallStage::Configured, databaseSettings());
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->get('/install')->assertNotFound();
});

it('is closed when the recorded database cannot be reached', function () {
    markers()->checkpoint()->write(InstallStage::Migrating, databaseSettings());
    config([
        'database.connections.unreachable' => array_merge(config('database.connections.'.config('database.default')), [
            'host' => '127.0.0.1', 'port' => 1, 'options' => [PDO::ATTR_TIMEOUT => 1],
        ]),
        'installer.marker_connection' => 'unreachable',
    ]);

    $this->get('/install')->assertNotFound();
});

it('stays open when no database has been recorded and the configured one cannot be reached', function () {
    // A fresh .env still holds .env.example's database settings.
    config([
        'database.connections.unreachable' => array_merge(config('database.connections.'.config('database.default')), [
            'host' => '127.0.0.1', 'port' => 1, 'options' => [PDO::ATTR_TIMEOUT => 1],
        ]),
        'installer.marker_connection' => 'unreachable',
    ]);

    $this->get('/install')->assertOk();
});

it('reads the database marker with a short timeout by default', function () {
    config([
        'installer.marker_connection' => null,
        'database.connections.'.config('database.default').'.host' => '10.255.255.1',
        'installer.database_timeout' => 1,
    ]);

    $started = microtime(true);

    expect(markers()->databaseMarker())->toBe(DatabaseMarker::Unreachable)
        ->and(microtime(true) - $started)->toBeLessThan(5);
});

it('stays closed without APP_KEY once a marker exists', function () {
    markers()->writeLock('1.0.0');
    config(['app.key' => '']);
    $this->app->forgetInstance('encrypter');

    $this->get('/install')->assertNotFound();
});

it('shows only pass or fail per group on the public first screen', function () {
    $content = $this->get('/install')->assertOk()->getContent();

    expect($content)->toContain('PHP')->toContain('Folders')->toContain('Security')->toContain('Build')
        ->not->toContain(base_path())
        ->not->toContain(PHP_VERSION)
        ->not->toContain('max_execution_time');
});
