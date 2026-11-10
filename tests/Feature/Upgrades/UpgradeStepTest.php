<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Http\Controllers\Console\UpgradeController;
use App\Installer\Finishing\ApplicationOptimizer;
use App\Installer\InstallationRecord;
use App\Installer\InstallStage;
use App\Installer\Upgrades\PendingUpgrade;
use App\Installer\Upgrades\UpgradeLock;
use App\Installer\Upgrades\UpgradeMaintenance;
use App\Platform\Staff\StaffPermission;
use Carbon\CarbonImmutable;
use Database\Seeders\InstalledVersionSeeder;
use Database\Seeders\InstallSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/upgrade-world.php';

/*
 * P09-06: the console's upgrade step for hosts without SSH (INSTALLER.md
 * § 10 step 6): super-admins only, after a fresh password and code and a
 * backup tick; one piece of work per request (a batch of migrations, then
 * the install seeders and caches) with the installer's progress bar;
 * maintenance mode for the upgrade on at the start and off at the end;
 * refused when nothing is pending or while another request runs it;
 * audited; forward only.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    config(['app.maintenance.driver' => 'array', 'installer.migrations_per_batch' => 1]);
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    $this->admin = staffMember();
    $this->optimizer = new class implements ApplicationOptimizer
    {
        public int $calls = 0;

        public function cache(): void
        {
            $this->calls++;
        }
    };
    $this->app->instance(ApplicationOptimizer::class, $this->optimizer);
});

afterEach(fn () => removeUpgradeFiles());

function confirmUpgrade(mixed $test, ?string $password = 'x-long-password-1', bool $backedUp = true): TestResponse
{
    return $test->post($test->console.'/system/upgrade/confirm', array_filter([
        'password' => $password, 'code' => upgradeCode($test->admin), 'backed_up' => $backedUp ? '1' : null,
    ]));
}

function runUpgradeStep(mixed $test): TestResponse
{
    return $test->post($test->console.'/system/upgrade/run');
}

it('acceptance: a super-admin runs the pending migrations in batches, then the install seeders, behind maintenance mode', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    $names = pendingMigrations(2);
    // A permission the release adds, not in the database yet: the seeders add it.
    Permission::query()->where('name', StaffPermission::SystemUpgrade->value)->delete();
    $this->actingAs($this->admin, 'staff');

    $page = $this->get($this->console.'/system/upgrade')->assertOk()->getContent();
    expect($page)->toContain('Confirm it is you', 'name="backed_up"', 'name="code"')
        ->and(upgradeFigures($page))->toMatchArray(['upgrade.from' => '1.4.0', 'upgrade.to' => '1.5.0', 'upgrade.maintenance' => 'off']);

    confirmUpgrade($this)->assertStatus(303)->assertRedirect($this->console.'/system/upgrade');

    // First request: one migration (one per batch here), maintenance mode on.
    runUpgradeStep($this)->assertStatus(303);
    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeTrue()
        ->and(DB::table('settings')->where('key', 'upgrade.test.2')->exists())->toBeFalse()
        ->and(app(UpgradeMaintenance::class)->forUpgrade())->toBeTrue();

    $page = $this->get($this->console.'/system/upgrade')->assertOk()->getContent();
    $total = app(PendingUpgrade::class)->state()->migrations->total;
    expect($page)->toContain('1 migration ran ('.($total - 1).' of '.$total.').', 'id="upgrade-run"', 'form.submit()')
        ->and(upgradeFigures($page)['upgrade.maintenance'])->toBe('on for the upgrade (the console stays open)');

    // Visitors get the maintenance page; the console keeps working.
    $this->get('/')->assertStatus(503);

    runUpgradeStep($this)->assertStatus(303);
    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Next: the install seeders and caches.');
    expect($this->optimizer->calls)->toBe(0);

    // Last request: the seeders and the caches; maintenance mode off.
    runUpgradeStep($this)->assertStatus(303);
    $page = $this->get($this->console.'/system/upgrade')->assertOk()->getContent();

    expect($page)->toContain('Upgraded to 1.5.0. Maintenance mode is off: the site is open again.', 'data-upgrade-done')
        ->and($page)->not->toContain('form.submit()')
        ->and(DB::table('migrations')->whereIn('migration', $names)->count())->toBe(2)
        ->and(DB::table(InstallationRecord::TABLE)->value('version'))->toBe('1.5.0')
        ->and(Permission::query()->where('name', StaffPermission::SystemUpgrade->value)->where('guard_name', 'staff')->exists())->toBeTrue()
        ->and($this->optimizer->calls)->toBe(1)
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse()
        ->and(app(PendingUpgrade::class)->state()->pending())->toBeFalse();
    $this->get('/')->assertOk();

    $events = AuditEntry::query()->where('log_name', 'operations')->where('event', 'like', 'upgrade.%')->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['upgrade.maintenance_on', 'upgrade.migrated', 'upgrade.migrated', 'upgrade.finished'])
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$this->admin->id])
        ->and($events[1]->properties->toArray())->toMatchArray(['from' => '1.4.0', 'to' => '1.5.0', 'ran' => [$names[0]]])
        ->and($events[3]->properties->toArray())->toMatchArray(['from' => '1.4.0', 'to' => '1.5.0', 'maintenance_ended' => true]);

    // Nothing left: refused.
    runUpgradeStep($this);
    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Nothing to run: every migration has run and the install seeders ran for 1.5.0.');
});

it('runs a release that brings no migration: the install seeders only, in one request', function () {
    upgradeRelease('1.5.1', database: '1.5.0');
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    runUpgradeStep($this);

    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Upgraded to 1.5.1.')
        ->and(DB::table(InstallationRecord::TABLE)->value('version'))->toBe('1.5.1')
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'like', 'upgrade.%')->pluck('event')->all())->toBe(['upgrade.maintenance_on', 'upgrade.finished']);
});

it('refuses when nothing is pending, without touching maintenance mode', function () {
    upgradeRelease('1.5.0', database: '1.5.0');
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    runUpgradeStep($this);

    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Nothing to run: every migration has run and the install seeders ran for 1.5.0.')
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse()
        ->and($this->optimizer->calls)->toBe(0)
        ->and(AuditEntry::query()->where('event', 'like', 'upgrade.%')->exists())->toBeFalse();
});

it('needs a fresh password, authenticator code and the backup tick before any step', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    pendingMigrations(1);
    $this->actingAs($this->admin, 'staff');

    runUpgradeStep($this)->assertSessionHasErrors(['password' => 'Confirm it is you first: your password and authenticator code (they stay confirmed for 10 minutes).']);
    confirmUpgrade($this, password: 'wrong-password')->assertSessionHasErrors(['password' => 'The password or code is not right.']);
    confirmUpgrade($this, backedUp: false)->assertSessionHasErrors('backed_up');
    runUpgradeStep($this);

    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeFalse()
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse();

    confirmUpgrade($this)->assertSessionHasNoErrors();
    runUpgradeStep($this);
    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeTrue();

    // The confirmation lasts 10 minutes.
    $this->travel(11)->minutes();
    $this->actingAs($this->admin, 'staff');
    runUpgradeStep($this)->assertSessionHasErrors('password');
});

it('asks for the backup tick even when the password and code are still confirmed, for each release', function () {
    // P09-06 rehearsal (2026-11-10): turning maintenance mode on from the
    // Version page confirms the password and code; the upgrade page then
    // skipped its form, and with it the backup tick.
    upgradeRelease('1.5.0', database: '1.4.0');
    pendingMigrations(1);
    $this->actingAs($this->admin, 'staff')->withSession(['console.step_up_at' => now()->getTimestamp()]);

    $page = $this->get($this->console.'/system/upgrade')->assertOk()->getContent();
    expect($page)->toContain('Before you start', 'name="backed_up"')
        ->not->toContain('name="password"', 'id="upgrade-run"');

    runUpgradeStep($this)->assertSessionHasErrors(['backed_up' => UpgradeController::BACKUP_TICK]);
    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeFalse();

    // A tick given for another release does not count.
    $this->withSession([UpgradeController::BACKED_UP => '1.4.9']);
    runUpgradeStep($this)->assertSessionHasErrors('backed_up');

    // The tick alone, no password again while it is fresh.
    $this->post($this->console.'/system/upgrade/confirm', ['backed_up' => '1'])->assertSessionHasNoErrors();
    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('id="upgrade-run"', 'Run the upgrade');
    runUpgradeStep($this);
    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeTrue();
});

it('is for system.upgrade only: super-admins; technical-admins and others get 403', function (string $role) {
    upgradeRelease('1.5.0', database: '1.4.0');
    pendingMigrations(1);
    $staff = staffNamed('Other', [$role]);
    $this->actingAs($staff, 'staff');

    $this->get($this->console.'/system/upgrade')->assertForbidden();
    $this->post($this->console.'/system/upgrade/confirm', ['password' => 'x-long-password-1', 'code' => upgradeCode($staff), 'backed_up' => '1'])->assertForbidden();
    $this->post($this->console.'/system/upgrade/run')->assertForbidden();

    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeFalse()
        ->and(StaffPermission::SystemUpgrade->isProtected())->toBeTrue();
})->with(['technical-admin', 'operations-manager', 'support-agent', 'finance-admin', 'compliance-officer', 'content-manager', 'analyst']);

it('sends staff without 2FA to enrol and signed-out visitors to the sign-in page', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    $this->post($this->console.'/system/upgrade/run')->assertRedirect();
    expect(app(UpgradeMaintenance::class)->active())->toBeFalse();

    $this->actingAs(staffNamed('Nofa', ['super-admin'], withTwoFactor: false), 'staff')
        ->post($this->console.'/system/upgrade/run')
        ->assertRedirect(route('filament.console.auth.multi-factor-authentication.set-up-required'));
    expect(app(UpgradeMaintenance::class)->active())->toBeFalse();
});

it('refuses while another request runs the step (two staff at once)', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    pendingMigrations(1);
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    // Another request holds the lock: an exclusive flock on the same file.
    $path = app(UpgradeLock::class)->path();
    File::ensureDirectoryExists(dirname($path));
    $other = fopen($path, 'c');
    expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();

    runUpgradeStep($this);
    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Another staff member is running the upgrade right now.')
        ->and(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeFalse()
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse();

    flock($other, LOCK_UN);
    fclose($other);

    runUpgradeStep($this);
    expect(DB::table('settings')->where('key', 'upgrade.test.1')->exists())->toBeTrue();
});

it('stops at a failing migration, names it and keeps the site in maintenance mode; the next try carries on', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    [$first, $broken] = pendingMigrations(2, broken: 2);
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    runUpgradeStep($this);
    runUpgradeStep($this);
    $page = $this->get($this->console.'/system/upgrade')->getContent();

    expect($page)->toContain("Migration {$broken} failed. The site stays in maintenance mode.", 'table_that_does_not_exist', 'Try this step again', 'Never roll migrations back')
        ->and($page)->not->toContain('form.submit()')
        ->and($page)->not->toContain('(Connection:')
        ->and(app(UpgradeMaintenance::class)->forUpgrade())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'upgrade.migration_failed')->first()?->properties['migration'])->toBe($broken);
    $this->get('/')->assertStatus(503);

    // The cause is fixed.
    DB::table('settings')->insert(['scope_type' => 'global', 'scope_id' => 0, 'key' => 'upgrade.test.fixed', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()]);

    runUpgradeStep($this);
    runUpgradeStep($this);

    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('Upgraded to 1.5.0.')
        ->and(DB::table('migrations')->whereIn('migration', [$first, $broken])->count())->toBe(2)
        ->and(app(UpgradeMaintenance::class)->active())->toBeFalse();
});

it('keeps the site in maintenance mode when the seeders or caches fail', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    $this->app->instance(ApplicationOptimizer::class, new class implements ApplicationOptimizer
    {
        public function cache(): void
        {
            throw new RuntimeException('php artisan route:cache failed.');
        }
    });
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    runUpgradeStep($this);

    expect($this->get($this->console.'/system/upgrade')->getContent())->toContain('The install seeders or the caches failed. The site stays in maintenance mode.', 'route:cache failed')
        ->and(app(UpgradeMaintenance::class)->forUpgrade())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'upgrade.finish_failed')->exists())->toBeTrue();
});

it('leaves maintenance mode on at the end when it was already on before the step', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    app(UpgradeMaintenance::class)->start();
    $this->actingAs($this->admin, 'staff');
    confirmUpgrade($this);

    runUpgradeStep($this);

    // Turned on for the upgrade (Version page): the step ends it too.
    expect(app(UpgradeMaintenance::class)->active())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'like', 'upgrade.%')->pluck('event')->all())->toBe(['upgrade.finished']);
});

it('keeps the console, its Livewire updates and scripts open in maintenance mode for an upgrade, nothing else', function () {
    app(UpgradeMaintenance::class)->start();

    $this->get('/')->assertStatus(503);
    $this->get('/login')->assertStatus(503);
    $this->get($this->console.'/login')->assertOk();
    // The script console pages load (minified unless debugging).
    $this->get(EndpointResolver::scriptPath(! config('app.debug')))->assertOk();
    $this->actingAs($this->admin, 'staff')->get($this->console.'/system/version')->assertOk();
    // A Livewire update from a customer page is not the console's.
    $this->postJson(EndpointResolver::updatePath(), ['components' => [['snapshot' => json_encode(['memo' => ['path' => 'o/acme/bills']])]]])->assertStatus(503);
});

it('only ever runs migrations forward: the upgrade code never resets, rolls back or reinstalls', function () {
    $code = collect(File::allFiles(app_path('Installer/Upgrades')))->map(fn ($file): string => $file->getContents())->implode("\n");

    foreach (['migrate:rollback', 'migrate:reset', 'migrate:fresh', 'migrate:refresh', 'db:wipe', 'FreshDatabaseReset', 'Finalizer', 'InstallationSteps', 'SetupToken', '->rollback(', '->reset('] as $token) {
        expect(str_contains($code, $token))->toBeFalse("App\\Installer\\Upgrades mentions {$token}");
    }
});

describe('the database records the release whose install seeders ran', function () {
    it('records it on a finished installation, once', function () {
        upgradeRelease('1.5.0', database: '1.4.0');
        $before = DB::table(InstallationRecord::TABLE)->first();

        $this->seed(InstalledVersionSeeder::class);
        $after = DB::table(InstallationRecord::TABLE)->first();
        $this->travel(1)->minutes();
        $this->seed(InstalledVersionSeeder::class);

        expect($after->version)->toBe('1.5.0')
            ->and($after->status)->toBe('finalized')
            ->and($after->finalized_at)->toBe($before->finalized_at)
            ->and(DB::table(InstallationRecord::TABLE)->value('updated_at'))->toBe($after->updated_at);
    });

    it('writes nothing during an install, without the row, or for code without a release number', function () {
        upgradeRelease('1.5.0');
        app(InstallationRecord::class)->record(DB::connection(), InstallStage::Migrating, '1.5.0-rc.1');
        $this->seed(InstalledVersionSeeder::class);
        expect(DB::table(InstallationRecord::TABLE)->value('version'))->toBe('1.5.0-rc.1');

        DB::table(InstallationRecord::TABLE)->delete();
        $this->seed(InstalledVersionSeeder::class);
        expect(DB::table(InstallationRecord::TABLE)->count())->toBe(0);

        config(['installer.paths.version' => '/nonexistent/VERSION']);
        app(InstallationRecord::class)->record(DB::connection(), InstallStage::Finalized, '1.4.0');
        $this->seed(InstalledVersionSeeder::class);
        expect(DB::table(InstallationRecord::TABLE)->value('version'))->toBe('1.4.0');
    });

    it('is the last of the install seeders', function () {
        expect(InstallSeeder::SEEDERS)->toContain(InstalledVersionSeeder::class)
            ->and(InstallSeeder::SEEDERS[array_key_last(InstallSeeder::SEEDERS)])->toBe(InstalledVersionSeeder::class);
    });
});
