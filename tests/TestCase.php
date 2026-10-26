<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reconnectDatabaseCache();
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
