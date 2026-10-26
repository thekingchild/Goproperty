<?php

declare(strict_types=1);

use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * P00-05: after APP_KEY, every public route shows "being set up" until both
 * the lock file and the database row say finalized (INSTALLER.md § 3).
 */

it('opens the site when both markers say finalized', function () {
    $this->get('/')->assertOk();
    $this->get('/login')->assertOk();
});

it('shows "being set up" on every public route before the install', function (string $method, string $path) {
    $this->markNotInstalled();

    $response = $this->call($method, $path);

    $response->assertStatus(503)
        ->assertHeader('Retry-After', '300')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertCookieMissing(config('session.cookie'));

    expect($response->getContent())->toContain('GoProperty is being set up')
        ->toContain('<meta name="robots" content="noindex, nofollow">');
})->with([
    ['GET', '/'],
    ['GET', '/login'],
    ['GET', '/register'],
    ['POST', '/register'],
    ['POST', '/login'],
    ['GET', '/dashboard'],
    ['GET', '/o/acme'],
    ['POST', '/webhooks/paystack'],
]);

it('keeps the site closed when only one marker says finalized', function (string $missing) {
    if ($missing === 'lock') {
        File::delete((string) config('installer.paths.lock'));
    } else {
        DB::table(InstallationRecord::TABLE)->update(['status' => InstallStage::OwnerReady->value]);
    }

    $this->get('/')->assertStatus(503)->assertSee('is being set up');
})->with(['lock', 'database row']);

it('keeps the installer and health check reachable before the install', function () {
    $this->markNotInstalled();

    $this->get('/up')->assertOk();
    $this->get('/install')->assertOk();
});

it('says "temporarily unavailable" when the lock exists but the database cannot be read', function () {
    $this->app->instance(InstallationRecord::class, new class extends InstallationRecord
    {
        public function stage(ConnectionInterface $connection): ?InstallStage
        {
            throw new RuntimeException('Connection refused');
        }
    });

    $this->get('/')
        ->assertStatus(503)
        ->assertSee('is temporarily unavailable')
        ->assertDontSee('being set up');
});

it('mentions the development seeder only on a local copy', function () {
    $this->markNotInstalled();

    $this->get('/')->assertDontSee('php artisan migrate --seed');

    $this->app['env'] = 'local';

    $this->get('/')->assertSee('php artisan migrate --seed');
});
