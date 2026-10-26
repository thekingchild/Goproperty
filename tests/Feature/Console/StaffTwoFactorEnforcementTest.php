<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Platform\Filament\Auth\AcceptStaffInvitation;
use App\Platform\Filament\Pages\Settings\BrandAppearance;
use App\Platform\Filament\Resources\Staff\StaffResource;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffPermission;
use App\Platform\Staff\StaffRoles;
use Filament\Facades\Filament;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P01-07 acceptance: "Staff without 2FA are forced to enrol"
 * (PLATFORM_CONSOLE.md § 1, UX_DESIGN.md § 3.2) on every console page,
 * including invited staff on their first sign-in, and they hold no console
 * permission until they have.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->setUp = route('filament.console.auth.multi-factor-authentication.set-up-required');
});

/**
 * Every GET page of the console that a signed-in staff member can open,
 * with its parameters filled in.
 *
 * @return list<string>
 */
function consolePageUrls(PlatformStaff $someone): array
{
    $exempt = [
        'filament.console.auth.login',
        'filament.console.auth.logout',
        'filament.console.auth.multi-factor-authentication.set-up-required',
        'filament.console.auth.staff-invitation',
    ];
    $role = StaffRole::query()->where('name', 'analyst')->firstOrFail();
    $organization = null;
    $customerUser = null;
    $urls = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'filament.console.') || in_array($name, $exempt, true) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = match ($parameter) {
                'record' => match (true) {
                    str_contains($route->uri(), 'staff-roles') => $role->id,
                    str_contains($route->uri(), 'customer-users') => ($customerUser ??= User::factory()->create())->id,
                    str_contains($route->uri(), 'customers') => ($organization ??= Organization::factory()->create())->id,
                    default => $someone->id,
                },
                'surface' => 'landing',
                default => throw new RuntimeException("Fill the {$parameter} parameter of {$name} in this test."),
            };
        }

        $urls[] = route($name, $parameters);
    }

    return $urls;
}

it('sends staff without 2FA to enrol from every console page, whatever their roles', function () {
    $staff = staffMember(withTwoFactor: false);
    $urls = consolePageUrls($staff);

    // The list really covers the console: staff, roles, approvals, settings, previews.
    expect(count($urls))->toBeGreaterThan(12)
        ->and(implode(' ', $urls))->toContain('/staff-roles', '/approvals', '/settings/brand', '/theme-preview/landing', '/staff/'.$staff->id, '/customers/', '/customer-users/');

    foreach ($urls as $url) {
        $this->actingAs($staff, 'staff')->get($url)->assertRedirect($this->setUp);
    }

    $this->actingAs($staff, 'staff')->get($this->setUp)->assertOk();
});

it('gives staff without 2FA no console permission, even as super-admin', function () {
    $staff = staffMember(withTwoFactor: false);

    expect($staff->isSuperAdmin())->toBeTrue()
        ->and($staff->staffPermissionNames())->toBe([])
        ->and(Gate::forUser($staff)->allows('settings.theme'))->toBeFalse()
        ->and(Gate::forUser($staff)->allows('viewAny', PlatformStaff::class))->toBeFalse();

    // A Livewire request that skipped the page redirect still finds nothing allowed.
    $this->actingAs($staff, 'staff');
    expect(BrandAppearance::canAccess())->toBeFalse()
        ->and(StaffResource::canAccess())->toBeFalse();

    $staff->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    $staff->forgetStaffRoles();

    expect($staff->holdsStaffPermission(StaffPermission::SettingsTheme))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('settings.theme'))->toBeTrue();
});

it('makes invited staff set a password and then enrol 2FA on first sign-in before anything else', function () {
    Notification::fake();
    $inviter = staffMember();

    [$invitee] = app(StaffLifecycle::class)->invite($inviter, 'Ngozi Eze', 'ngozi@goproperty.ng', null, [StaffRole::query()->where('name', 'support-agent')->firstOrFail()]);

    $token = null;
    Notification::assertSentTo($invitee, StaffInvitation::class, function (StaffInvitation $notification) use ($invitee, &$token): bool {
        $url = (string) $notification->toMail($invitee)->actionUrl;
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        return str_starts_with($url, route('filament.console.auth.staff-invitation', ['token' => $token]));
    });

    expect($invitee->status)->toBe(StaffStatus::Invited);

    // Before accepting, the account cannot use the console at all.
    $this->actingAs($invitee, 'staff')->get('/'.config('app.admin_path'))->assertForbidden();
    auth('staff')->logout();

    $this->get(route('filament.console.auth.staff-invitation', ['token' => $token]))->assertOk()->assertSee('ngozi@goproperty.ng');

    Livewire::test(AcceptStaffInvitation::class, ['token' => $token])
        ->fillForm(['password' => 'a-new-Long-password-1', 'passwordConfirmation' => 'a-new-Long-password-1'])
        ->call('accept')
        ->assertRedirect($this->setUp);

    $invitee->refresh();
    expect(auth('staff')->id())->toBe($invitee->id)
        ->and($invitee->status)->toBe(StaffStatus::Invited)
        ->and($invitee->invitation_accepted_at)->not->toBeNull()
        ->and($invitee->hasTwoFactorEnabled())->toBeFalse()
        ->and($invitee->staffPermissionNames())->toBe([]);

    // Every console page sends them to enrol 2FA.
    foreach (consolePageUrls($invitee) as $url) {
        $this->actingAs($invitee, 'staff')->get($url)->assertRedirect($this->setUp);
    }

    $this->actingAs($invitee, 'staff')->get($this->setUp)->assertOk();

    // Once enrolled, the next request activates the account.
    $invitee->saveAppAuthenticationSecret(app(Google2FA::class)->generateSecretKey());
    $this->actingAs($invitee->fresh(), 'staff')->get('/'.config('app.admin_path'))->assertOk();

    $invitee->refresh();
    expect($invitee->status)->toBe(StaffStatus::Active)
        ->and($invitee->holdsStaffPermission(StaffPermission::CustomersView))->toBeTrue()
        ->and($invitee->holdsStaffPermission(StaffPermission::StaffView))->toBeFalse();
});

it('opens every one of those pages once 2FA is enrolled (super-admin)', function () {
    $staff = staffMember();
    app(StaffRoles::class)->grant($staff, staffNamed('Bola'), StaffRole::query()->where('name', 'finance-admin')->firstOrFail());

    foreach (consolePageUrls($staff) as $url) {
        $response = $this->actingAs($staff, 'staff')->get($url);

        expect($response->isSuccessful())->toBeTrue("{$url} answered {$response->status()}");
    }
});
