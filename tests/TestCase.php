<?php

declare(strict_types=1);

namespace Tests;

use App\Installer\InstallationMarkers;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reconnectDatabaseCache();
        $this->useTemporaryInstallerFiles();

        // Tests see an installed site unless they say otherwise; without both
        // markers every page shows "being set up" (INSTALLER.md § 3).
        $traits = class_uses_recursive(static::class);

        if (isset($traits[RefreshDatabase::class]) || isset($traits[DatabaseTruncation::class])) {
            $this->markInstalled();
        }
    }

    public function markInstalled(): void
    {
        $this->app->make(InstallationMarkers::class)->writeLock('testing');
        $this->app->make(InstallationRecord::class)->record(DB::connection(), InstallStage::Finalized, 'testing');
    }

    /** A copy that has not been installed: no lock file, no database row. */
    public function markNotInstalled(): void
    {
        File::delete((string) config('installer.paths.lock'));
        DB::table(InstallationRecord::TABLE)->delete();
    }

    /**
     * Installer markers and secrets go to a folder of this test's own, never
     * the checkout's storage/ (a lock file there would open a developer's
     * local site, a missing one would close it).
     */
    private function useTemporaryInstallerFiles(): void
    {
        $directory = sys_get_temp_dir().'/goproperty-installer-'.Str::random(16);

        config([
            'installer.paths.lock' => $directory.'/installed.lock',
            'installer.paths.checkpoint' => $directory.'/private/install.checkpoint',
            'installer.paths.setup_token' => $directory.'/private/setup.token.hash',
            'installer.paths.unlock_challenge' => $directory.'/private/unlock.challenge',
            'installer.paths.unlock_file' => $directory.'/install-unlock.txt',
            'installer.paths.grant' => $directory.'/private/install.grant',
            'installer.paths.attempts' => $directory.'/private/install.attempts',
            'installer.paths.record' => $directory.'/private/installation-record.txt',
            // The test's own connection, so the marker sees rows written
            // inside the test's transaction.
            'installer.marker_connection' => config('database.default'),
            'logging.channels.install.path' => $directory.'/install.log',
        ]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
    }

    public function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * The suite uses the database cache (shared-hosting profile). In parallel
     * runs Laravel moves each worker to its own database after the app has
     * booted, but Fortify's rate limiter has already built the cache store on
     * the old connection. That connection's first query reconnects the
     * default connection and drops the test's RefreshDatabase transaction,
     * so point the store at the current connection.
     */
    private function reconnectDatabaseCache(): void
    {
        $store = $this->app->make('cache')->store()->getStore();

        if (! $store instanceof DatabaseStore) {
            return;
        }

        $config = config('cache.stores.'.config('cache.default'));
        $connection = is_array($config) ? ($config['connection'] ?? null) : null;
        $lockConnection = is_array($config) ? ($config['lock_connection'] ?? $connection) : null;

        $store->setConnection(DB::connection(is_string($connection) ? $connection : null));
        $store->setLockConnection(DB::connection(is_string($lockConnection) ? $lockConnection : null));
    }
}
