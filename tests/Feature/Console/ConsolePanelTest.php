<?php

declare(strict_types=1);

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Models\User;
use App\Platform\Console\ConsoleRequests;
use App\Platform\Filament\Auth\StaffLogin;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use PragmaRX\Google2FA\Google2FA;

/*
 * P00-08 acceptance: the console is auth-protected and empty, and a customer
 * session cannot reach it (ONBOARDING_COMPLIANCE.md § 1, PLATFORM_CONSOLE.md).
 */

function consoleUrl(string $path = ''): string
{
    return '/'.config('app.admin_path').($path !== '' ? '/'.$path : '');
}

function activeStaff(array $attributes = []): PlatformStaff
{
    return staffMember(array_merge([
        'name' => 'Ada Obi',
        'email' => 'ada@goproperty.ng',
        'password' => 'correct-Horse-battery-9',
        'status' => StaffStatus::Active,
    ], $attributes));
}

it('sends guests to the console sign-in', function () {
    $this->get(consoleUrl())->assertRedirect(consoleUrl('login'));
});

it('shows the console sign-in page', function () {
    $this->get(consoleUrl('login'))->assertOk()->assertSee('Sign in');
});

it('keeps a signed-in customer out', function () {
    $this->actingAs(User::factory()->create(), 'web')
        ->get(consoleUrl())
        ->assertRedirect(consoleUrl('login'));
});

it('opens an empty console for active staff', function () {
    $this->actingAs(activeStaff(), 'staff')
        ->get(consoleUrl())
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('filament.info')
        ->assertDontSee('Welcome');
});

it('refuses staff who are not active', function (StaffStatus $status) {
    $this->actingAs(activeStaff(['status' => $status]), 'staff')
        ->get(consoleUrl())
        ->assertForbidden();
})->with([StaffStatus::Invited, StaffStatus::Deactivated]);

it('signs staff in on the staff guard only, with their 2FA code', function () {
    $staff = activeStaff();
    Filament::setCurrentPanel(Filament::getPanel('console'));

    $login = Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'ada@goproperty.ng', 'password' => 'correct-Horse-battery-9'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('staff')->check())->toBeFalse();

    $login->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret))
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('staff')->check())->toBeTrue()
        ->and(auth('web')->check())->toBeFalse();
});

it('refuses a customer\'s password on the console sign-in', function () {
    User::factory()->create(['email' => 'test@example.com']);
    Filament::setCurrentPanel(Filament::getPanel('console'));

    Livewire::test(StaffLogin::class)
        ->fillForm(['email' => 'test@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth('staff')->check())->toBeFalse();
});

it('uses its own session cookie', function () {
    $response = $this->get(consoleUrl('login'));
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'gp_staff_session');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('strict')
        ->and($cookie->getPath())->toBe('/')
        ->and(collect($response->headers->getCookies())->pluck('name'))->not->toContain(config('session.cookie'));
});

it('leaves customer pages on the customer cookie', function () {
    $response = $this->get('/login');

    expect(collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName()))
        ->toContain(config('session.cookie'))
        ->not->toContain('gp_staff_session');
});

it('never prints the console path on customer pages', function (string $path) {
    $user = User::factory()->create();

    $this->actingAs($user)->get($path)->assertDontSee(config('app.admin_path'));
})->with(['/', '/login', '/dashboard', '/portal', '/owner']);

it('answers addresses outside the allowlist with a plain 404', function () {
    config(['console.ip_allowlist' => ['10.0.0.8']]);

    $this->get(consoleUrl('login'))->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get(consoleUrl('login'))->assertOk();
});

it('logs guesses at an admin path as probes', function (string $path) {
    Log::spy();

    $this->get($path)->assertNotFound();

    Log::shouldHaveReceived('notice')->with('Console path probe', Mockery::on(fn (array $context) => $context['path'] === $path));
})->with(['/admin', '/admin/login', '/administrator', '/wp-admin', '/wp-login.php']);

it('does not log ordinary missing pages as probes', function () {
    Log::spy();

    $this->get('/no-such-page')->assertNotFound();

    Log::shouldNotHaveReceived('notice');
});

it('recognises console requests, including Livewire updates from console pages', function () {
    $console = app(ConsoleRequests::class);
    $path = config('app.admin_path');
    $update = ltrim(EndpointResolver::updatePath(), '/');

    $livewire = fn (array $pages) => Request::create('/'.$update, 'POST', ['components' => array_map(
        fn (string $page) => ['snapshot' => json_encode(['memo' => ['path' => $page]])],
        $pages,
    )]);

    expect($console->isConsoleRequest(Request::create("/{$path}")))->toBeTrue()
        ->and($console->isConsoleRequest(Request::create("/{$path}/login")))->toBeTrue()
        ->and($console->isConsoleRequest(Request::create("/{$path}-evil")))->toBeFalse()
        ->and($console->isConsoleRequest(Request::create('/dashboard')))->toBeFalse()
        ->and($console->isConsoleRequest($livewire(["{$path}/login"])))->toBeTrue()
        ->and($console->isConsoleRequest($livewire(['settings/profile'])))->toBeFalse()
        ->and($console->isConsoleRequest($livewire(["{$path}", 'settings/profile'])))->toBeFalse()
        ->and($console->isConsoleRequest(Request::create('/'.$update, 'POST', ['components' => 'x'])))->toBeFalse();
});

it('serves the console on its own host when ADMIN_DOMAIN is set', function () {
    config(['app.admin_domain' => 'ops.goproperty.test', 'app.admin_path' => '']);
    $console = app(ConsoleRequests::class);

    expect($console->path())->toBe('')
        ->and($console->isConsoleRequest(Request::create('https://ops.goproperty.test/')))->toBeTrue()
        ->and($console->isConsoleRequest(Request::create('https://goproperty.test/')))->toBeFalse();
});

it('keeps the console off when ADMIN_PATH breaks the rules', function (string $path) {
    config(['app.admin_path' => $path]);

    expect(app(ConsoleRequests::class)->enabled())->toBeFalse();

    $routes = Process::path(base_path())
        ->env(['ADMIN_PATH' => $path, 'APP_ENV' => 'testing'])
        ->run([PHP_BINARY, 'artisan', 'route:list', '--json']);

    expect($routes->successful())->toBeTrue($routes->errorOutput())
        ->and($routes->output())->not->toContain('filament.console');
})->with(['admin', 'short', 'Has-Capitals-1']);
