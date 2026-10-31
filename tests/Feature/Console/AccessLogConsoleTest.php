<?php

declare(strict_types=1);

use App\Audit\AccessLog;
use App\Audit\AuditEntry;
use App\Audit\AuthenticationLog;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\User;
use App\Platform\Audit\AccessLogEntry;
use App\Platform\Filament\Resources\AccessLog\Pages\ListAccessLog;
use App\Platform\Staff\StaffPermission;
use Database\Seeders\StaffRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * P06-07c (PLATFORM_CONSOLE.md § 8, P06-subtasks default 61): Console →
 * Audit → Access log, the authentication log of customers and staff.
 * `access_log.view` (super-admin and technical-admin by default),
 * read-only, every opening audited once.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');
    $this->ada = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $this->ngozi = User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']);
    $this->kunle = User::factory()->create(['name' => 'Kunle Agent', 'email' => 'kunle@other.test']);
    $this->tobi = staffNamed('Tobi', ['technical-admin']);
});

/** @param  array<string, mixed>  $attributes */
function signInRow(string $type, ?int $id, string $event, array $attributes = []): AuthenticationLog
{
    return AuthenticationLog::query()->create($attributes + [
        'authenticatable_type' => $type,
        'authenticatable_id' => $id,
        'event' => $event,
        'ip' => '102.89.1.10',
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
        'location' => 'NG',
        'is_new_device' => false,
        'created_at' => now(),
    ]);
}

/** @return list<AuditEntry> */
function accessLogViews(): array
{
    return AuditEntry::query()->where('log_name', 'audit')->where('event', 'access_log.viewed')->orderBy('id')->get()->all();
}

it('opens for super-admins and technical admins, and answers 403 for every other default role', function () {
    signInRow('user', $this->ngozi->id, 'login_success');

    foreach ([$this->ada, $this->tobi] as $staff) {
        $this->flushSession();
        $this->actingAs($staff, 'staff')->get($this->console.'/access-log')->assertOk()->assertSee('Ngozi Owner')->assertSee('Signed in');
    }

    foreach (['support-agent', 'operations-manager', 'finance-admin', 'compliance-officer', 'content-manager', 'analyst'] as $role) {
        $this->flushSession();
        $staff = staffNamed('Other'.str_replace('-', '', $role), [$role]);

        $this->actingAs($staff, 'staff')->get($this->console.'/access-log')->assertForbidden();
        expect(Gate::forUser($staff)->allows('viewAny', AccessLogEntry::class))->toBeFalse();
    }
});

it('refuses staff without 2FA', function () {
    $no2fa = staffMember(['name' => 'Yemi', 'email' => 'yemi@goproperty.ng'], withTwoFactor: false, roles: ['technical-admin']);

    $this->actingAs($no2fa, 'staff')->get($this->console.'/access-log')
        ->assertRedirect(route('filament.console.auth.multi-factor-authentication.set-up-required'));
    expect(Gate::forUser($no2fa)->allows('viewAny', AccessLogEntry::class))->toBeFalse()
        ->and(accessLogViews())->toBe([]);
});

it('adds access_log.view to the catalogue (area Audit) and gives it to technical-admin on an existing install', function () {
    expect(StaffPermission::AccessLogView->area())->toBe('Audit')
        ->and(StaffPermission::grouped()['Audit'])->toHaveKey('access_log.view');

    // An install seeded before P06-07c: the permission is new to the catalogue.
    Permission::query()->where('name', 'access_log.view')->where('guard_name', 'staff')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(StaffRole::query()->where('name', 'technical-admin')->firstOrFail()->permissionNames())->not->toContain('access_log.view');

    $this->seed(StaffRolesSeeder::class);

    $holders = StaffRole::query()->whereHas('permissions', fn ($query) => $query->where('name', 'access_log.view'))->pluck('name')->sort()->values()->all();
    expect($holders)->toBe(['super-admin', 'technical-admin']);
});

it('lists both guards newest first, with the account, event, IP, location, browser and new device', function () {
    $staffRow = signInRow('platform_staff', $this->tobi->id, '2fa_passed', ['is_new_device' => null]);
    $unknown = signInRow('user', null, 'login_failed', ['attempted_identifier' => app(AccessLog::class)->hashIdentifier('nobody@example.test')]);
    $newDevice = signInRow('user', $this->ngozi->id, 'login_success', ['is_new_device' => true]);

    $this->actingAs($this->ada, 'staff');
    Livewire::test(ListAccessLog::class)
        ->assertCanSeeTableRecords([$newDevice, $unknown, $staffRow], inOrder: true)
        ->assertTableColumnStateSet('account', 'Unknown account', $unknown)
        ->assertTableColumnStateSet('account', 'Tobi', $staffRow)
        ->assertTableColumnStateSet('account', 'Ngozi Owner', $newDevice)
        ->assertTableColumnFormattedStateSet('event', 'Sign-in failed', $unknown)
        ->assertTableColumnFormattedStateSet('event', 'Two-factor code accepted', $staffRow)
        ->assertSee('ngozi@acme.test')
        ->assertSee('102.89.1.10')
        ->assertSee('NG');

    expect(AccessLogEntry::query()->findOrFail($newDevice->id)->shortUserAgent())->toStartWith('Mozilla/5.0 (Linux; Android 14)')->toEndWith('...')->toHaveLength(63);
});

it('filters by account type, event, new device, IP and dates', function () {
    $staffRow = signInRow('platform_staff', $this->tobi->id, 'login_success');
    $failed = signInRow('user', $this->ngozi->id, 'login_failed', ['ip' => '41.58.2.2']);
    $newDevice = signInRow('user', $this->kunle->id, 'login_success', ['is_new_device' => true]);
    $this->travel(3)->days();
    $later = signInRow('user', $this->ngozi->id, 'logout');

    $this->actingAs($this->ada, 'staff');
    Livewire::test(ListAccessLog::class)
        ->filterTable('authenticatable_type', 'platform_staff')
        ->assertCanSeeTableRecords([$staffRow])->assertCanNotSeeTableRecords([$failed, $newDevice, $later])
        ->resetTableFilters()
        ->filterTable('event', 'login_failed')
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$staffRow, $newDevice, $later])
        ->resetTableFilters()
        ->filterTable('is_new_device', true)
        ->assertCanSeeTableRecords([$newDevice])->assertCanNotSeeTableRecords([$staffRow, $failed, $later])
        ->resetTableFilters()
        ->filterTable('ip', ['ip' => '41.58.2.2'])
        ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$staffRow, $newDevice, $later])
        ->resetTableFilters()
        ->filterTable('dates', ['from' => now('Africa/Lagos')->toDateString()])
        ->assertCanSeeTableRecords([$later])->assertCanNotSeeTableRecords([$staffRow, $failed, $newDevice]);
});

it('searches by email: the accounts with it, customer or staff, and failed attempts that typed it', function () {
    $access = app(AccessLog::class);
    $ngoziIn = signInRow('user', $this->ngozi->id, 'login_success');
    $ngoziFailed = signInRow('user', $this->ngozi->id, 'login_failed', ['attempted_identifier' => $access->hashIdentifier('ngozi@acme.test')]);
    $kunleIn = signInRow('user', $this->kunle->id, 'login_success');
    // Typed Ngozi's address on the staff sign-in: no staff account has it.
    $typedOnConsole = signInRow('platform_staff', null, 'login_failed', ['attempted_identifier' => $access->hashIdentifier('Ngozi@Acme.test')]);
    $typedOther = signInRow('user', null, 'login_failed', ['attempted_identifier' => $access->hashIdentifier('someone@else.test')]);
    $tobiIn = signInRow('platform_staff', $this->tobi->id, 'login_success');
    // A staff member's id equal to Ngozi's user id must not match her search.
    $sameIdStaff = signInRow('platform_staff', $this->ngozi->id, 'logout');

    $this->actingAs($this->ada, 'staff');
    Livewire::test(ListAccessLog::class)
        ->searchTable('  NGOZI@acme.test ')
        ->assertCanSeeTableRecords([$ngoziIn, $ngoziFailed, $typedOnConsole])
        ->assertCanNotSeeTableRecords([$kunleIn, $typedOther, $tobiIn, $sameIdStaff])
        ->searchTable('tobi@goproperty.ng')
        ->assertCanSeeTableRecords([$tobiIn])
        ->assertCanNotSeeTableRecords([$ngoziIn, $ngoziFailed, $kunleIn, $typedOnConsole, $typedOther])
        ->searchTable('nobody-at-all@example.test')
        ->assertCanNotSeeTableRecords([$ngoziIn, $ngoziFailed, $kunleIn, $typedOnConsole, $typedOther, $tobiIn, $sameIdStaff]);
});

it('writes one access_log.viewed entry per page load, without the searched address in clear', function () {
    signInRow('user', $this->ngozi->id, 'login_success');

    $this->actingAs($this->tobi, 'staff')->get($this->console.'/access-log?'.http_build_query(['filters' => ['event' => ['value' => 'login_failed']], 'search' => 'ngozi@acme.test']))->assertOk();

    $views = accessLogViews();
    expect($views)->toHaveCount(1)
        ->and($views[0]->causer_type)->toBe((new PlatformStaff)->getMorphClass())
        ->and($views[0]->causer_id)->toBe($this->tobi->id)
        ->and($views[0]->getProperty('filters'))->toEqual(['event' => ['value' => 'login_failed']])
        ->and($views[0]->getProperty('searched_identifier'))->toBe(app(AccessLog::class)->hashIdentifier('ngozi@acme.test'))
        ->and(json_encode($views[0]->properties))->not->toContain('ngozi@acme.test');

    Livewire::test(ListAccessLog::class)
        ->filterTable('event', 'logout')
        ->searchTable('kunle@other.test')
        ->call('gotoPage', 2);

    expect(accessLogViews())->toHaveCount(2);
});

it('is read-only: no create, edit or delete, by policy, by route and on the model', function () {
    $row = AccessLogEntry::query()->findOrFail(signInRow('user', $this->ngozi->id, 'login_success')->id);

    expect(Gate::forUser($this->ada)->allows('create', AccessLogEntry::class))->toBeFalse()
        ->and(Gate::forUser($this->ada)->allows('update', $row))->toBeFalse()
        ->and(Gate::forUser($this->ada)->allows('delete', $row))->toBeFalse()
        ->and(Route::has('filament.console.resources.access-log.edit'))->toBeFalse()
        ->and(Route::has('filament.console.resources.access-log.view'))->toBeFalse()
        ->and(fn () => $row->forceFill(['event' => 'logout'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);

    $this->actingAs($this->ada, 'staff');
    Livewire::test(ListAccessLog::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    expect(AuthenticationLog::query()->findOrFail($row->id)->event)->toBe('login_success');
});

it('escapes HTML in the user agent and the account name', function () {
    $mallory = User::factory()->create(['name' => '<b>Mallory</b>', 'email' => 'mallory@evil.test']);
    signInRow('user', $mallory->id, 'login_failed', ['user_agent' => '<script>alert(1)</script>']);

    $this->actingAs($this->ada, 'staff')->get($this->console.'/access-log')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<b>Mallory</b>', false)
        ->assertSee('&lt;b&gt;Mallory&lt;/b&gt;', false);
});
