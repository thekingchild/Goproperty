<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationFeatureOverride;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\Features;
use App\Plans\PlanGate;
use App\Plans\PlanLimitReached;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerChangeRefused;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\FeaturesRelationManager;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Platform\Plans\FeatureOverrides;
use App\Platform\Staff\StaffPermission;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Database\Seeders\StaffRolesSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-07a: feature overrides on the organization's page (ARCHITECTURE.md
 * § 6.10, PLATFORM_CONSOLE.md § 1B; docs/plans/P06-subtasks.md default 53):
 * every feature but SMS credits with the plan's value, the override in
 * force and what applies; Set override / End override with
 * `customers.override_features` (operations-manager, super-admin), through
 * App\Platform\Plans\FeatureOverrides, audited. An override wins over the
 * plan in Features and PlanGate until it ends.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    joinAs(User::factory()->create(['name' => 'Bayo Agent']), $this->acme, OrganizationRole::Agent);
    joinAs(User::factory()->create(['name' => 'Chika Agent']), $this->acme, OrganizationRole::Agent);
    activeSubscription($this->acme, 'starter');
});

function featuresTable(Organization $organization): mixed
{
    return Livewire::test(FeaturesRelationManager::class, ['ownerRecord' => Customer::query()->findOrFail($organization->id), 'pageClass' => ViewCustomer::class]);
}

it('lists every feature but SMS credits with the plan\'s value, the override in force and what applies', function () {
    $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff');

    $keys = array_keys(featuresTable($this->acme)->instance()->getTableRecords()->all());
    expect($keys)->toBe(array_map(fn (Feature $feature): string => $feature->value, FeatureOverrides::overridable()))
        ->and($keys)->not->toContain('sms_credits')
        ->and($keys)->toHaveCount(count(Feature::cases()) - 1);

    featuresTable($this->acme)
        ->assertTableColumnStateSet('plan', 'Not included', 'whatsapp')
        ->assertTableColumnStateSet('override', null, 'whatsapp')
        ->assertTableColumnStateSet('applies', 'Not included', 'whatsapp')
        ->assertTableColumnStateSet('plan', '3', 'staff_users')
        ->assertTableColumnStateSet('applies', '3', 'staff_users');

    $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()->assertSee('Plan features');
});

it('acceptance: switches WhatsApp on for a Starter organization, winning over the plan, until it ends', function () {
    Notification::fake(); // forget the first invoice's notice
    $operator = staffNamed('Tunde', ['operations-manager']);
    $this->actingAs($operator, 'staff');
    $gate = app(PlanGate::class);
    expect(fn () => $gate->ensureFeature(Feature::WhatsApp, $this->acme))->toThrow(PlanLimitReached::class);

    featuresTable($this->acme)
        ->assertTableActionVisible('setOverride', 'whatsapp')
        ->assertTableActionHidden('endOverride', 'whatsapp')
        ->callTableAction('setOverride', 'whatsapp', data: ['enabled' => '1', 'reason' => 'Pilot of WhatsApp reminders', 'until' => '2026-12-31'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('WhatsApp notifications is overridden for Acme Homes.')
        ->assertTableColumnStateSet('override', 'Included', 'whatsapp')
        ->assertTableColumnStateSet('applies', 'Included', 'whatsapp')
        ->assertTableColumnStateSet('plan', 'Not included', 'whatsapp')
        ->assertSee('Pilot of WhatsApp reminders — by Tunde, until 31 Dec 2026')
        ->assertTableActionVisible('endOverride', 'whatsapp');

    expect(app(Features::class)->enabled(Feature::WhatsApp, $this->acme))->toBeTrue();
    $gate->ensureFeature(Feature::WhatsApp, $this->acme);

    $override = inOrganization($this->acme, fn (): OrganizationFeatureOverride => OrganizationFeatureOverride::query()->sole());
    expect($override->only(['feature_key', 'enabled', 'limit_value', 'reason', 'granted_by_staff_id']))
        ->toBe(['feature_key' => 'whatsapp', 'enabled' => true, 'limit_value' => null, 'reason' => 'Pilot of WhatsApp reminders', 'granted_by_staff_id' => $operator->id])
        ->and($override->expires_at?->getTimestamp())->toBe(CarbonImmutable::parse('2026-12-31', 'Africa/Lagos')->getTimestamp());

    $entry = AuditEntry::query()->where('event', 'feature_override.set')->sole();
    expect($entry->log_name)->toBe('customers')
        ->and($entry->subject_type)->toBe((new Organization)->getMorphClass())
        ->and($entry->subject_id)->toBe($this->acme->id)
        ->and($entry->causer_type)->toBe((new PlatformStaff)->getMorphClass())
        ->and($entry->causer_id)->toBe($operator->id)
        ->and($entry->reason)->toBe('Pilot of WhatsApp reminders')
        ->and($entry->properties['feature'] ?? null)->toBe('whatsapp')
        ->and($entry->properties['organization_id'] ?? null)->toBe($this->acme->id)
        ->and($entry->properties->get('old', 'missing'))->toBeNull()
        ->and($entry->properties['attributes'] ?? null)->toEqual(['value' => 'Included', 'enabled' => true, 'limit' => null, 'until' => CarbonImmutable::parse('2026-12-31', 'Africa/Lagos')->utc()->toIso8601String()]);

    Livewire::test(HistoryRelationManager::class, ['ownerRecord' => Customer::query()->findOrFail($this->acme->id), 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$entry])
        ->assertSee('Tunde (staff)');

    // The customer is not told.
    Notification::assertNothingSentTo($this->owner);

    // On its end date the plan applies again by itself; the row is kept.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 00:01', 'Africa/Lagos'));
    expect(app(Features::class)->enabled(Feature::WhatsApp, $this->acme))->toBeFalse()
        ->and(fn () => $gate->ensureFeature(Feature::WhatsApp, $this->acme))->toThrow(PlanLimitReached::class)
        ->and(inOrganization($this->acme, fn (): int => OrganizationFeatureOverride::query()->count()))->toBe(1);
    featuresTable($this->acme)
        ->assertTableColumnStateSet('override', null, 'whatsapp')
        ->assertTableActionHidden('endOverride', 'whatsapp');

    $this->artisan('goproperty:audit-verify')->assertSuccessful();
});

it('acceptance: raises the staff users limit, then unlimited, each replacing the override, and ends it', function () {
    $operator = staffNamed('Tunde', ['operations-manager']);
    $this->actingAs($operator, 'staff');
    $gate = app(PlanGate::class);

    // Starter: 3 staff users, all taken.
    expect(fn () => $gate->ensureRoomFor(Feature::StaffUsers, $this->acme))->toThrow(PlanLimitReached::class);

    featuresTable($this->acme)
        ->callTableAction('setOverride', 'staff_users', data: ['limit' => 5, 'reason' => 'Agreed while they move to Growth'])
        ->assertHasNoTableActionErrors()
        ->assertTableColumnStateSet('override', '5', 'staff_users')
        ->assertTableColumnStateSet('applies', '5', 'staff_users')
        ->assertSee('no end');
    $gate->ensureRoomFor(Feature::StaffUsers, $this->acme);
    expect(app(Features::class)->limit(Feature::StaffUsers, $this->acme))->toBe(5);

    featuresTable($this->acme)
        ->callTableAction('setOverride', 'staff_users', data: ['limit' => null, 'reason' => 'Unlimited for the migration week'])
        ->assertHasNoTableActionErrors()
        ->assertTableColumnStateSet('applies', 'Unlimited', 'staff_users');
    expect(app(Features::class)->limit(Feature::StaffUsers, $this->acme))->toBeNull()
        ->and(inOrganization($this->acme, fn (): int => OrganizationFeatureOverride::query()->count()))->toBe(1);

    $replaced = AuditEntry::query()->where('event', 'feature_override.set')->latest('id')->firstOrFail();
    expect($replaced->properties['old'] ?? null)->toEqual(['value' => '5', 'enabled' => true, 'limit' => 5, 'until' => null])
        ->and($replaced->properties['attributes'] ?? null)->toEqual(['value' => 'Unlimited', 'enabled' => true, 'limit' => null, 'until' => null]);

    featuresTable($this->acme)
        ->callTableAction('endOverride', 'staff_users', data: ['reason' => 'Moved to Growth'])
        ->assertNotified('The override of Staff users has ended: the plan applies again.')
        ->assertTableColumnStateSet('override', null, 'staff_users')
        ->assertTableColumnStateSet('applies', '3', 'staff_users');

    $override = inOrganization($this->acme, fn (): OrganizationFeatureOverride => OrganizationFeatureOverride::query()->sole());
    expect($override->expires_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and(app(Features::class)->limit(Feature::StaffUsers, $this->acme))->toBe(3)
        ->and(fn () => $gate->ensureRoomFor(Feature::StaffUsers, $this->acme))->toThrow(PlanLimitReached::class);

    $ended = AuditEntry::query()->where('event', 'feature_override.ended')->sole();
    expect($ended->causer_id)->toBe($operator->id)
        ->and($ended->subject_id)->toBe($this->acme->id)
        ->and($ended->reason)->toBe('Moved to Growth')
        ->and($ended->properties['old'] ?? null)->toEqual(['value' => 'Unlimited', 'enabled' => true, 'limit' => null, 'until' => null]);

    // Nothing to end any more.
    expect(fn () => app(FeatureOverrides::class)->end($operator, $this->acme, Feature::StaffUsers))->toThrow(CustomerChangeRefused::class, 'no override of Staff users in force');
});

it('switches a feature off for one organization only', function () {
    $operator = staffNamed('Tunde', ['operations-manager']);
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($operator, planNamed('growth')));
    $beta = Organization::factory()->create(['name' => 'Beta Homes']);
    activeSubscription($beta, 'growth');

    app(FeatureOverrides::class)->set($operator, $this->acme, Feature::OwnPaystack, false, null, 'Paystack account under review');

    expect(app(Features::class)->enabled(Feature::OwnPaystack, $this->acme))->toBeFalse()
        ->and(fn () => app(PlanGate::class)->ensureFeature(Feature::OwnPaystack, $this->acme))->toThrow(PlanLimitReached::class)
        ->and(app(Features::class)->enabled(Feature::OwnPaystack, $beta))->toBeTrue();
});

it('requires a reason of 3 to 500 characters and an end in the future, and never overrides SMS credits', function () {
    $operator = staffNamed('Tunde', ['operations-manager']);
    $this->actingAs($operator, 'staff');
    $overrides = app(FeatureOverrides::class);

    featuresTable($this->acme)
        ->callTableAction('setOverride', 'whatsapp', data: ['enabled' => '1', 'reason' => 'ok'])
        ->assertHasTableActionErrors(['reason' => 'min']);
    featuresTable($this->acme)
        ->callTableAction('setOverride', 'whatsapp', data: ['reason' => 'Switch missing'])
        ->assertHasTableActionErrors(['enabled' => 'required']);
    featuresTable($this->acme)
        ->callTableAction('setOverride', 'staff_users', data: ['limit' => -1, 'reason' => 'Negative'])
        ->assertHasTableActionErrors(['limit']);

    expect(fn () => $overrides->set($operator, $this->acme, Feature::WhatsApp, true, null, '  ab '))->toThrow(CustomerChangeRefused::class, 'reason of 3 to 500')
        ->and(fn () => $overrides->set($operator, $this->acme, Feature::WhatsApp, true, null, str_repeat('x', 501)))->toThrow(CustomerChangeRefused::class, 'reason of 3 to 500')
        ->and(fn () => $overrides->set($operator, $this->acme, Feature::WhatsApp, true, null, 'Yesterday', CarbonImmutable::now()->subDay()))->toThrow(CustomerChangeRefused::class, 'in the future')
        ->and(fn () => $overrides->set($operator, $this->acme, Feature::WhatsApp, null, null, 'No switch'))->toThrow(CustomerChangeRefused::class, 'switched on or off')
        ->and(fn () => $overrides->set($operator, $this->acme, Feature::StaffUsers, null, 100_001, 'Too many'))->toThrow(CustomerChangeRefused::class, 'between 0 and 100,000')
        ->and(fn () => $overrides->set($operator, $this->acme, Feature::SmsCredits, null, 5_000, 'More SMS'))->toThrow(CustomerChangeRefused::class, 'SMS allowance')
        ->and(fn () => $overrides->end($operator, $this->acme, Feature::SmsCredits))->toThrow(CustomerChangeRefused::class, 'SMS allowance');

    expect(inOrganization($this->acme, fn (): int => OrganizationFeatureOverride::query()->count()))->toBe(0)
        ->and(AuditEntry::query()->where('event', 'like', 'feature_override.%')->exists())->toBeFalse();
});

describe('who may override features', function () {
    it('gives the permission to operations-manager and super-admin by default, also on an installation seeded before', function () {
        $holders = StaffRole::query()->whereHas('permissions', fn ($query) => $query->where('name', StaffPermission::CustomersOverrideFeatures->value))->pluck('name')->sort()->values()->all();
        expect($holders)->toBe(['operations-manager', 'super-admin'])
            ->and(StaffPermission::CustomersOverrideFeatures->area())->toBe('Customers')
            ->and(StaffPermission::CustomersOverrideFeatures->label())->toBe('Grant or withhold plan features for a customer');

        // Seeded before the permission existed: the seeder adds it to those default roles only.
        Permission::query()->where('name', StaffPermission::CustomersOverrideFeatures->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(StaffRolesSeeder::class);

        $holders = StaffRole::query()->whereHas('permissions', fn ($query) => $query->where('name', StaffPermission::CustomersOverrideFeatures->value))->pluck('name')->sort()->values()->all();
        expect($holders)->toBe(['operations-manager', 'super-admin']);
    });

    it('offers Set override to operations-manager and super-admin', function (string $role) {
        $staff = staffNamed('Tunde', [$role]);
        $this->actingAs($staff, 'staff');

        featuresTable($this->acme)->assertTableActionVisible('setOverride', 'whatsapp');
        expect(Gate::forUser($staff)->allows('overrideFeatures', Customer::query()->findOrFail($this->acme->id)))->toBeTrue();
    })->with(['operations-manager', 'super-admin']);

    it('shows the features but hides and refuses the overrides for every other role that opens the page', function (string $role) {
        $staff = staffNamed('Sade', [$role]);
        $this->actingAs($staff, 'staff');
        app(FeatureOverrides::class)->set(staffNamed('Root', ['super-admin']), $this->acme, Feature::WhatsApp, true, null, 'Pilot');

        featuresTable($this->acme)
            ->assertTableColumnStateSet('applies', 'Included', 'whatsapp')
            ->assertTableActionHidden('setOverride', 'whatsapp')
            ->assertTableActionHidden('endOverride', 'whatsapp')
            // A direct call mounts nothing.
            ->call('mountTableAction', 'setOverride', 'staff_users')
            ->assertSet('mountedActions', []);

        expectOverridesRefused($this, $staff);
    })->with(['support-agent', 'finance-admin', 'compliance-officer']);

    it('refuses the overrides to staff who cannot open Customers, and to a super-admin without 2FA', function (?string $role) {
        $staff = $role !== null ? staffNamed('Sade', [$role]) : staffMember(['name' => 'Kunle', 'email' => 'kunle@goproperty.ng'], withTwoFactor: false);
        app(FeatureOverrides::class)->set(staffNamed('Root', ['super-admin']), $this->acme, Feature::WhatsApp, true, null, 'Pilot');

        expect(FeaturesRelationManager::canViewForRecord(Customer::query()->findOrFail($this->acme->id), ViewCustomer::class))->toBeFalse();
        expectOverridesRefused($this, $staff);
    })->with(['analyst', 'technical-admin', 'content-manager', null]);
});

/** The ability and both service calls are refused, and nothing changes. */
function expectOverridesRefused(mixed $test, PlatformStaff $staff): void
{
    $overrides = app(FeatureOverrides::class);

    expect(Gate::forUser($staff)->allows('overrideFeatures', Customer::query()->findOrFail($test->acme->id)))->toBeFalse()
        ->and(fn () => $overrides->set($staff, $test->acme, Feature::StaffUsers, null, 50, 'Trying anyway'))->toThrow(CustomerChangeRefused::class, 'permission')
        ->and(fn () => $overrides->end($staff, $test->acme, Feature::WhatsApp, 'Trying anyway'))->toThrow(CustomerChangeRefused::class, 'permission')
        ->and(app(Features::class)->limit(Feature::StaffUsers, $test->acme))->toBe(3)
        ->and(app(Features::class)->enabled(Feature::WhatsApp, $test->acme))->toBeTrue()
        ->and(AuditEntry::query()->where('causer_id', $staff->id)->where('causer_type', (new PlatformStaff)->getMorphClass())->exists())->toBeFalse();
}
