<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Mail\Templates\EmailTemplates;
use App\Models\EmailTemplate;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Models\SubscriptionPlan;
use App\Models\TierApplication;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Platform\Compliance\DocumentLinks;
use App\Platform\Filament\Auth\AcceptStaffInvitation;
use App\Platform\Filament\Pages\Settings\BrandAppearance;
use App\Platform\Filament\Resources\Staff\StaffResource;
use App\Platform\Staff\DefaultStaffRole;
use App\Platform\Staff\StaffLifecycle;
use App\Platform\Staff\StaffPermission;
use App\Platform\Staff\StaffRoles;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Filament\Facades\Filament;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use PragmaRX\Google2FA\Google2FA;

/*
 * P01-07 acceptance: "Staff without 2FA are forced to enrol"
 * (PLATFORM_CONSOLE.md § 1, UX_DESIGN.md § 3.2) on every console page,
 * including invited staff on their first sign-in, and they hold no console
 * permission until they have.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Storage::fake('compliance');
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
    $application = null;
    $tierApplication = function (): TierApplication {
        $organization = Organization::factory()->create();

        return tierApplication($organization, joinAs(User::factory()->create(), $organization));
    };
    $emailTemplate = function (): EmailTemplate {
        app(EmailTemplates::class)->seed();

        return EmailTemplate::query()->orderBy('id')->firstOrFail();
    };
    // A GoProperty invoice and a Paystack payment of it (P06-07b), in an organization of their own.
    $platformPayment = null;
    $billingRecords = function () use (&$platformPayment): PlatformPayment {
        return $platformPayment ??= app(OrganizationContext::class)->run(Organization::factory()->create(), function (): PlatformPayment {
            $invoice = new PlatformInvoice;
            $invoice->forceFill([
                'number' => 'PR-2026-'.random_int(100_000, 999_999), 'kind' => InvoiceKind::SmsCredits, 'sms_credits' => 500,
                'subtotal_kobo' => Money::naira(3_000), 'vat_kobo' => Money::naira(225), 'total_kobo' => Money::naira(3_225), 'status' => InvoiceStatus::Open,
            ])->save();
            $payment = new PlatformPayment;
            $payment->forceFill([
                'platform_invoice_id' => $invoice->id, 'method' => PlatformPayment::PAYSTACK, 'amount_kobo' => Money::naira(3_225), 'currency' => 'NGN',
                'paystack_reference' => 'PINV-'.$invoice->number.'-a1', 'status' => PlatformPaymentStatus::Pending,
            ])->save();

            return $payment;
        });
    };
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
                    str_contains($route->uri(), 'tier-applications') => ($application ??= $tierApplication())->id,
                    str_contains($route->uri(), 'staff-roles') => $role->id,
                    // An email template (P05-04): seeded from the code's catalog.
                    str_contains($route->uri(), 'email-templates') => $emailTemplate()->id,
                    // A plan (P06-01a): the launch plans are seeded by a migration.
                    str_contains($route->uri(), 'plans') => SubscriptionPlan::query()->orderBy('id')->firstOrFail()->id,
                    str_contains($route->uri(), 'platform-invoices') => $billingRecords()->platform_invoice_id,
                    str_contains($route->uri(), 'platform-payments') => $billingRecords()->id,
                    str_contains($route->uri(), 'customer-users') => ($customerUser ??= User::factory()->create())->id,
                    str_contains($route->uri(), 'customers') => ($organization ??= Organization::factory()->create())->id,
                    // An audit log entry (P06-07c).
                    str_contains($route->uri(), 'audit-log') => ($auditEntry ??= AuditEntry::query()->findOrFail(activity('test')->event('test.page')->causedBy($someone)->log('A page test entry')?->getKey()))->id,
                    str_contains($route->uri(), 'impersonation-log') => ($supportSession ??= ImpersonationSession::query()->forceCreate([
                        'staff_id' => $someone->id,
                        'user_id' => ($customerUser ??= User::factory()->create())->id,
                        'organization_id' => ($organization ??= Organization::factory()->create())->id,
                        'reason' => 'Ticket 1',
                        'mode' => 'read_only',
                        'started_at' => now(),
                        'expires_at' => now()->addMinutes(30),
                    ]))->id,
                    default => $someone->id,
                },
                'surface' => 'landing',
                // A compliance document's file (P01-03), reached through its signed link.
                'document' => DB::table('compliance_documents')->where('tier_application_id', ($application ??= $tierApplication())->id)->value('id'),
                default => throw new RuntimeException("Fill the {$parameter} parameter of {$name} in this test."),
            };
        }

        $urls[] = $name === DocumentLinks::ROUTE
            ? URL::temporarySignedRoute($name, now()->addMinutes(5), $parameters)
            : route($name, $parameters);
    }

    return $urls;
}

it('sends staff without 2FA to enrol from every console page, whatever their roles', function () {
    $staff = staffMember(withTwoFactor: false);
    $urls = consolePageUrls($staff);

    // The list really covers the console: staff, roles, approvals, settings, previews.
    expect(count($urls))->toBeGreaterThan(12)
        ->and(implode(' ', $urls))->toContain('/staff-roles', '/approvals', '/settings/brand', '/theme-preview/landing', '/staff/'.$staff->id, '/customers/', '/customer-users/', '/tier-applications/', '/document-expiry', '/compliance-documents/', '/impersonation-log/', '/audit-log/', '/access-log');

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

/*
 * P06-07 acceptance: "Every page policy-checked per staff role". Every
 * console page, opened as each default role, either opens or answers 403
 * (never an error or a page that skips its check), and only the console's
 * home is open to every role: each other page is refused to at least one.
 */
it('acceptance: checks every console page against each default staff role', function () {
    $urls = consolePageUrls(staffMember());
    // The home, and the session keep-alive every signed-in page calls.
    $everyone = ['/'.config('app.admin_path'), '/'.config('app.admin_path').'/session/ping'];
    $openedBy = array_fill_keys($urls, []);

    foreach (DefaultStaffRole::values() as $role) {
        $staff = staffNamed('Role'.str_replace('-', '', $role), [$role]);

        foreach ($urls as $url) {
            $this->flushSession();
            $status = $this->actingAs($staff, 'staff')->get($url)->status();

            expect(in_array($status, [200, 204, 403], true))->toBeTrue("{$url} answered {$status} for {$role}");

            if ($status !== 403) {
                $openedBy[$url][] = $role;
            }
        }
    }

    foreach ($openedBy as $url => $roles) {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (in_array($path, $everyone, true)) {
            continue;
        }

        expect(count($roles))->toBeLessThan(count(DefaultStaffRole::values()), "{$path} opens for every role")
            ->and($roles)->toContain('super-admin');
    }

    // Spot checks of the P06-07 pages against PLATFORM_CONSOLE.md § 1.
    $opened = fn (string $suffix): array => collect($openedBy)->first(fn (array $roles, string $url): bool => str_ends_with((string) parse_url($url, PHP_URL_PATH), $suffix)) ?? [];
    expect($opened('/platform-payments'))->toEqualCanonicalizing(['super-admin', 'finance-admin'])
        ->and($opened('/platform-invoices'))->toEqualCanonicalizing(['super-admin', 'finance-admin', 'support-agent'])
        ->and($opened('/settings/refunds'))->toBe(['super-admin'])
        ->and($opened('/audit-log'))->toBe(['super-admin'])
        ->and($opened('/access-log'))->toEqualCanonicalizing(['super-admin', 'technical-admin'])
        ->and($opened('/customers'))->not->toContain('analyst');
});
