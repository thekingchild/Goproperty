<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Backup\BackupRun;
use App\Installer\Upgrades\LatestRelease;
use App\Installer\Upgrades\ReleaseCheckStatus;
use App\Installer\Upgrades\UpgradeMaintenance;
use App\Platform\Filament\Pages\SystemVersion;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/upgrade-world.php';

/*
 * P09-06: Console → Operations → Version (INSTALLER.md § 10 step 2,
 * PLATFORM_CONSOLE.md § 2): read-only — the release running, the latest on
 * GitHub (asked, cached, short timeouts, failures shown calmly; never in
 * tests without Http::fake()), pending migrations and seeders, the last
 * backups and maintenance mode; and, for super-admins, the link to the
 * upgrade step and maintenance mode for an upgrade (step-up, audited).
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    config(['app.maintenance.driver' => 'array']);
    Http::preventStrayRequests();
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    $this->admin = staffMember();
    $this->tech = staffNamed('Tobi', ['technical-admin']);
});

afterEach(fn () => removeUpgradeFiles());

/** GitHub's answer for the latest release. */
function githubRelease(string $tag = 'v1.5.0', int $status = 200): void
{
    Http::fake(['api.github.com/*' => Http::response($status === 200 ? [
        'tag_name' => $tag, 'name' => 'GoProperty '.$tag, 'html_url' => 'https://github.com/thekingchild/Goproperty/releases/tag/'.$tag,
        'published_at' => '2026-11-02T08:30:00Z', 'draft' => false, 'prerelease' => false,
        'assets' => [['browser_download_url' => 'https://github.com/thekingchild/Goproperty/releases/download/'.$tag.'/goproperty.zip']],
    ] : ['message' => 'Not Found'], $status)]);
}

function asProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

it('opens for system.status (technical-admin, super-admin) and nobody else', function (string $role, int $status) {
    $staff = $role === 'super-admin' ? $this->admin : ($role === 'technical-admin' ? $this->tech : staffNamed(ucfirst(str_replace('-', '', $role)), [$role]));

    $this->actingAs($staff, 'staff')->get($this->console.'/system/version')->assertStatus($status);
})->with([
    ['super-admin', 200], ['technical-admin', 200], ['operations-manager', 403], ['support-agent', 403],
    ['finance-admin', 403], ['compliance-officer', 403], ['content-manager', 403], ['analyst', 403],
]);

it('shows the release running, the database\'s release, pending migrations, maintenance mode and the last backups', function () {
    upgradeRelease('1.5.0', database: '1.4.0');
    $names = pendingMigrations(2);
    BackupRun::query()->create(['type' => 'db', 'filename' => 'goproperty-db/2026-11-04-01-30-00.zip', 'size_bytes' => 2048, 'status' => 'success', 'started_at' => '2026-11-04 01:30:00', 'finished_at' => '2026-11-04 01:31:00', 'triggered_by' => 'schedule']);
    BackupRun::query()->create(['type' => 'db', 'filename' => 'goproperty-db/2026-11-04-09-30-00.zip', 'status' => 'failed', 'started_at' => '2026-11-04 09:30:00', 'finished_at' => '2026-11-04 09:31:00', 'triggered_by' => 'staff']);

    $html = $this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->assertOk()->getContent();
    $figures = upgradeFigures($html);

    expect($figures['version.current'])->toBe('1.5.0')
        ->and($figures['version.database'])->toStartWith('1.4.0')->toContain('install seeders not run for 1.5.0')
        ->and($figures['version.pending'])->toBe('2')
        ->and($figures['version.pending.names'])->toContain($names[0], $names[1])
        ->and($figures['version.maintenance'])->toBe('Off')
        // The last *successful* one, in Lagos time.
        ->and($figures['version.backup.db'])->toBe('4 Nov 2026, 02:31 (2.0 KB)')
        ->and($figures['version.backup.files'])->toStartWith('never')
        ->and($figures['version.latest'])->toBe('GitHub is asked for the latest release only in production and staging.');

    $this->actingAs($this->tech, 'staff');
    Livewire::test(SystemVersion::class)->assertActionHidden('checkAgain');
    Http::assertNothingSent();
});

it('shows the latest release from GitHub in production, says when it is newer, and remembers it for six hours', function () {
    asProduction();
    upgradeRelease('1.4.0', database: '1.4.0');
    githubRelease('v1.5.0');

    $html = $this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->assertOk()->getContent();

    expect($html)->toContain('data-figure="version.latest.number">1.5.0</strong>', 'newer than this installation', 'published 2 Nov 2026, 09:30', 'href="https://github.com/thekingchild/Goproperty/releases/tag/v1.5.0"')
        // A link to the release page only: nothing to download or install from here.
        ->and($html)->not->toContain('releases/download')
        // Nothing pending, and a technical-admin: no link to the upgrade step.
        ->and($html)->not->toContain('/system/upgrade"');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/thekingchild/Goproperty/releases/latest' && $request->method() === 'GET');
    Http::assertSentCount(1);

    $this->travel(5)->hours();
    expect(app(LatestRelease::class)->check()->version)->toBe('1.5.0');
    Http::assertSentCount(1);

    $this->travel(2)->hours();
    expect(app(LatestRelease::class)->check()->version)->toBe('1.5.0');
    Http::assertSentCount(2);
});

it('says up to date when the latest release is the one running', function () {
    asProduction();
    upgradeRelease('1.5.0', database: '1.5.0');
    githubRelease('v1.5.0');

    expect($this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->getContent())->toContain('up to date')->not->toContain('newer than');
});

it('shows a GitHub failure calmly, asks again only after 15 minutes, and uses short timeouts', function (Closure $answer, string $message) {
    asProduction();
    upgradeRelease('1.4.0', database: '1.4.0');
    $calls = 0;
    Http::fake(function (Request $request) use ($answer, &$calls) {
        $calls++;

        return $answer();
    });

    $html = $this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->assertOk()->getContent();

    expect(upgradeFigures($html)['version.latest'])->toStartWith($message);

    $this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->assertOk();
    expect($calls)->toBe(1);

    $this->travel(16)->minutes();
    $this->actingAs($this->tech, 'staff')->get($this->console.'/system/version')->assertOk();
    expect($calls)->toBe(2);
})->with([
    'unreachable' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'GitHub could not be reached.'],
    'server error' => [fn () => Http::response('oops', 502), 'GitHub answered HTTP 502.'],
    'not a release number' => [fn () => Http::response(['tag_name' => 'nightly', 'html_url' => 'https://github.com/x/y'], 200), 'GitHub\'s latest release has no version number'],
]);

it('says when the repository has no published release, and links only to github.com', function () {
    asProduction();
    Http::fake(['api.github.com/*' => Http::sequence()
        ->push(['message' => 'Not Found'], 404)
        ->push(['tag_name' => 'v2.0.0', 'html_url' => 'https://evil.example/download', 'published_at' => 'not a date'])]);

    expect(app(LatestRelease::class)->check()->status)->toBe(ReleaseCheckStatus::NoRelease);

    app(LatestRelease::class)->forget();
    $check = app(LatestRelease::class)->check();

    expect($check->status)->toBe(ReleaseCheckStatus::Found)
        ->and($check->version)->toBe('2.0.0')
        ->and($check->url)->toBeNull()
        ->and($check->publishedAt)->toBeNull();
});

it('takes the repository from the settings, and asks nothing without a valid one', function () {
    asProduction();
    config(['installer.releases.repository' => 'acme/fork']);
    githubRelease('v1.6.0');

    expect(app(LatestRelease::class)->check()->version)->toBe('1.6.0');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.github.com/repos/acme/fork/'));

    config(['installer.releases.repository' => 'https://evil.example/x']);
    expect(app(LatestRelease::class)->check()->status)->toBe(ReleaseCheckStatus::NotChecked);
    Http::assertSentCount(1);
});

it('asks GitHub again on "Check GitHub again", at most five times in ten minutes', function () {
    asProduction();
    upgradeRelease('1.4.0', database: '1.4.0');
    githubRelease('v1.5.0');
    $this->actingAs($this->tech, 'staff');
    $page = Livewire::test(SystemVersion::class);
    Http::assertSentCount(1);

    $page->callAction('checkAgain')->assertNotified('Asked GitHub again.');
    Livewire::test(SystemVersion::class);
    Http::assertSentCount(2);

    foreach (range(1, 4) as $ignored) {
        $page->callAction('checkAgain');
    }

    $page->callAction('checkAgain')->assertNotified('Checked often enough: try again in a few minutes.');
});

it('links super-admins to the upgrade step while something is pending, never technical-admins', function () {
    upgradeRelease('1.5.0', database: '1.4.0');

    $this->actingAs($this->admin, 'staff');
    Livewire::test(SystemVersion::class)->assertActionVisible('upgrade')->assertActionVisible('maintenanceOn');

    $this->actingAs($this->tech, 'staff');
    Livewire::test(SystemVersion::class)->assertActionHidden('upgrade')->assertActionHidden('maintenanceOn');

    upgradeRelease('1.5.0', database: '1.5.0');
    $this->actingAs($this->admin, 'staff');
    $html = $this->get($this->console.'/system/version')->getContent();
    Livewire::test(SystemVersion::class)->assertActionHidden('upgrade');

    expect(upgradeFigures($html)['version.nothing'])->toBe('Nothing to run: every migration has run and the install seeders ran for 1.5.0.');
});

it('turns maintenance mode on and off for an upgrade after step-up, audited; visitors get 503 while the console stays open', function () {
    $this->actingAs($this->admin, 'staff');
    $page = Livewire::test(SystemVersion::class);

    $page->callAction('maintenanceOn', data: ['password' => 'wrong-password', 'code' => '000000'])->assertNotified('The password or code is not right.');
    expect(app(UpgradeMaintenance::class)->active())->toBeFalse();

    $page->callAction('maintenanceOn', data: ['password' => 'x-long-password-1', 'code' => upgradeCode($this->admin)])
        ->assertNotified('Maintenance mode is on. Visitors see the maintenance page; the console stays open.');

    expect(app(UpgradeMaintenance::class)->forUpgrade())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'upgrade.maintenance_on')->where('causer_id', $this->admin->id)->exists())->toBeTrue();

    $this->get('/')->assertStatus(503);
    $this->get('/login')->assertStatus(503);
    $this->get($this->console.'/system/version')->assertOk();
    expect(upgradeFigures($this->get($this->console.'/system/version')->getContent())['version.maintenance'])->toStartWith('On for an upgrade');

    // Step-up stays fresh for 10 minutes.
    Livewire::test(SystemVersion::class)->assertActionHidden('maintenanceOn')->callAction('maintenanceOff')->assertNotified('Maintenance mode is off.');

    expect(app(UpgradeMaintenance::class)->active())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'upgrade.maintenance_off')->exists())->toBeTrue();
    $this->get('/')->assertOk();
});

it('leaves maintenance mode from php artisan down alone: it closes the console too', function () {
    app()->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);

    expect(app(UpgradeMaintenance::class)->forUpgrade())->toBeFalse()
        ->and(app(UpgradeMaintenance::class)->end())->toBeFalse()
        ->and(app(UpgradeMaintenance::class)->start())->toBeFalse();

    $this->actingAs($this->admin, 'staff')->get($this->console.'/system/version')->assertStatus(503);
    expect(app()->maintenanceMode()->active())->toBeTrue();
});

it('has no action that downloads or replaces code', function () {
    $this->actingAs($this->admin, 'staff');
    upgradeRelease('1.5.0', database: '1.4.0');
    $actions = array_map(fn ($action): string => $action->getName(), Livewire::test(SystemVersion::class)->instance()->getCachedHeaderActions());

    expect($actions)->toEqualCanonicalizing(['upgrade', 'checkAgain', 'maintenanceOn', 'maintenanceOff']);
});
