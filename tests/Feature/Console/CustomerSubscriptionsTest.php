<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\BillingCycle;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionCancelledNotice;
use App\Notifications\SubscriptionReactivatedNotice;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerChangeRefused;
use App\Platform\Customers\CustomerSubscriptions;
use App\Platform\Customers\OrganizationSuspension;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Platform\Filament\Resources\Customers\Pages\ListCustomers;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-07a: a customer's subscription in the console (PLATFORM_CONSOLE.md
 * § 1B, BILLING_PAYMENTS.md § 2; docs/plans/P06-subtasks.md defaults 50,
 * 52, 54): shown on the organization's page and in the list; staff change
 * the plan (`customers.change_plan`), cancel and reactivate the
 * subscription (`customers.suspend`) with a reason, through
 * SubscriptionService. Acceptance of P06-07: every page and action is
 * policy-checked per staff role. Metering after a reactivation is proven in
 * Modules/SaasBilling/tests/Feature/StaffReactivationTest.php.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bayo Agent', 'email' => 'bayo@acme.test']), $this->acme, OrganizationRole::Agent);
    activeSubscription($this->acme, 'starter');
});

function consoleSubscriptionOf(Organization $organization): Subscription
{
    return inOrganization($organization, fn (): Subscription => Subscription::query()->sole());
}

function consoleCustomerOf(Organization $organization): Customer
{
    return Customer::query()->findOrFail($organization->id);
}

function consolePlanId(string $key): int
{
    return planNamed($key)->id;
}

describe('the subscription on the organization\'s page and in the list', function () {
    it('shows plan, billing, status and since, trial end, current period, units last billed and platform cover', function () {
        $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff');

        // Active since today: metering invoiced the rest of November at once (no units yet).
        $this->get($this->console.'/customers/'.$this->acme->id)->assertOk()
            ->assertSeeInOrder(['Subscription', 'Plan', 'Starter', 'Billing', 'Monthly', 'Status', 'Active', 'Status since', 'Nov 10, 2026', 'Trial ends', 'Current period', '10–30 November 2026', 'Units last billed', '0', 'Platform covered']);

        // In trial: no period and nothing billed yet.
        $trial = Organization::factory()->create(['name' => 'Trial Homes', 'platform_covered' => true]);
        startTrial($trial, 'growth', 14);
        $this->get($this->console.'/customers/'.$trial->id)->assertOk()
            ->assertSeeInOrder(['Growth', 'Monthly', 'Free trial', 'Trial ends', 'Nov 24, 2026', 'Current period', 'None yet', 'Units last billed', 'Not billed yet', 'Platform covered', 'Invoices are marked paid when issued.']);

        // No plan chosen yet.
        $new = Organization::factory()->create(['name' => 'New Homes']);
        $this->get($this->console.'/customers/'.$new->id)->assertOk()->assertSee('No subscription yet: the owner has not chosen a plan');
    });

    it('lists each organization\'s plan and subscription status, with filters for both', function () {
        $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff');
        $trial = Organization::factory()->create(['name' => 'Trial Homes']);
        startTrial($trial, 'growth');
        $none = Organization::factory()->create(['name' => 'New Homes']);

        Livewire::test(ListCustomers::class)
            ->assertTableColumnStateSet('subscription_plan', 'Starter', consoleCustomerOf($this->acme))
            ->assertTableColumnFormattedStateSet('subscription_status', 'Active', consoleCustomerOf($this->acme))
            ->assertTableColumnStateSet('subscription_plan', 'Growth', consoleCustomerOf($trial))
            ->assertTableColumnFormattedStateSet('subscription_status', 'Free trial', consoleCustomerOf($trial))
            ->assertTableColumnStateSet('subscription_plan', null, consoleCustomerOf($none))
            ->filterTable('subscription_status', [SubscriptionStatus::Trialing->value])
            ->assertCanSeeTableRecords([consoleCustomerOf($trial)])
            ->assertCanNotSeeTableRecords([consoleCustomerOf($this->acme), consoleCustomerOf($none)])
            ->filterTable('subscription_status', [CustomerResource::NO_SUBSCRIPTION, SubscriptionStatus::Active->value])
            ->assertCanSeeTableRecords([consoleCustomerOf($none), consoleCustomerOf($this->acme)])
            ->assertCanNotSeeTableRecords([consoleCustomerOf($trial)])
            ->resetTableFilters()
            ->filterTable('plan', consolePlanId('growth'))
            ->assertCanSeeTableRecords([consoleCustomerOf($trial)])
            ->assertCanNotSeeTableRecords([consoleCustomerOf($this->acme), consoleCustomerOf($none)]);
    });
});

describe('change plan', function () {
    it('acceptance: moves a customer to any plan and cycle at once, the reason in the audit entry, owners not emailed', function () {
        Notification::fake(); // forget the first invoice's notice
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        // A custom plan (Enterprise), which owners cannot choose themselves, yearly.
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->mountAction('changePlan')
            ->assertActionDataSet(['plan_id' => consolePlanId('starter'), 'billing_cycle' => 'monthly']);

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionVisible('changePlan')
            ->callAction('changePlan', data: ['plan_id' => consolePlanId('enterprise'), 'billing_cycle' => 'annual', 'reason' => 'Signed the Enterprise contract on 9 November'])
            ->assertHasNoActionErrors()
            ->assertNotified('Acme Homes is now on Enterprise, billed yearly.');

        $subscription = consoleSubscriptionOf($this->acme);
        expect($subscription->plan_id)->toBe(consolePlanId('enterprise'))
            ->and($subscription->billing_cycle)->toBe(BillingCycle::Annual)
            ->and($subscription->status)->toBe(SubscriptionStatus::Active);

        $entry = AuditEntry::query()->where('event', 'subscription.plan_changed')->latest('id')->firstOrFail();
        expect($entry->log_name)->toBe('subscriptions')
            ->and($entry->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and($entry->causer_id)->toBe($operator->id)
            ->and($entry->reason)->toBe('Signed the Enterprise contract on 9 November')
            ->and($entry->properties['old'] ?? null)->toEqual(['plan_id' => consolePlanId('starter'), 'billing_cycle' => 'monthly'])
            ->and($entry->properties['attributes'] ?? null)->toEqual(['plan_id' => consolePlanId('enterprise'), 'billing_cycle' => 'annual'])
            ->and($entry->properties['organization_id'] ?? null)->toBe($this->acme->id);

        Notification::assertNothingSent();

        // Shown on the organization's History with the staff member and reason.
        Livewire::test(HistoryRelationManager::class, ['ownerRecord' => consoleCustomerOf($this->acme), 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$entry])
            ->assertSee('Tunde (staff)')
            ->assertSee('Signed the Enterprise contract on 9 November');

        $this->artisan('goproperty:audit-verify')->assertSuccessful();
    });

    it('moves to a plan no longer offered and past the organization\'s limits, which owners may not', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($operator, planNamed('growth')));
        foreach (['Ada', 'Bisi', 'Chidi'] as $name) {
            joinAs(User::factory()->create(['name' => $name]), $this->acme, OrganizationRole::Agent);
        }
        DB::table('plans')->where('key', 'starter')->update(['is_active' => false]);
        catalogue()->flush();

        // The owner may not: not offered, and 5 staff users on a plan of 3.
        expect(fn () => inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('starter'))))
            ->toThrow(SubscriptionChangeRefused::class, 'no longer offered');

        expect(app(CustomerSubscriptions::class)->planOptions())->toContain('Starter (not offered)', 'Enterprise (custom)');

        $this->actingAs($operator, 'staff');
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('changePlan', data: ['plan_id' => consolePlanId('starter'), 'billing_cycle' => 'monthly', 'reason' => 'Back to Starter as the customer asked'])
            ->assertHasNoActionErrors()
            ->assertNotified('Acme Homes is now on Starter, billed monthly.');

        // Nobody is removed: the five members stay.
        expect(consoleSubscriptionOf($this->acme)->plan_id)->toBe(consolePlanId('starter'))
            ->and($this->acme->fresh()?->users()->count())->toBe(5);
    });

    it('requires a reason of 3 to 500 characters and refuses the plan it is already on', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('changePlan', data: ['plan_id' => consolePlanId('growth'), 'billing_cycle' => 'monthly', 'reason' => 'ok'])
            ->assertHasActionErrors(['reason' => 'min']);
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('changePlan', data: ['plan_id' => consolePlanId('growth'), 'billing_cycle' => 'monthly', 'reason' => str_repeat('x', 501)])
            ->assertHasActionErrors(['reason' => 'max']);
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('changePlan', data: ['plan_id' => consolePlanId('starter'), 'billing_cycle' => 'monthly', 'reason' => 'Nothing to change'])
            ->assertNotified('Acme Homes is already on Starter.');

        $service = app(CustomerSubscriptions::class);
        expect(fn () => $service->changePlan($operator, $this->acme, consolePlanId('growth'), BillingCycle::Monthly, '  ab  '))->toThrow(CustomerChangeRefused::class, 'at least 3')
            ->and(fn () => $service->changePlan($operator, $this->acme, consolePlanId('growth'), BillingCycle::Monthly, str_repeat('x', 501)))->toThrow(CustomerChangeRefused::class, 'longer than 500')
            ->and(fn () => $service->changePlan($operator, $this->acme, 999_999, BillingCycle::Monthly, 'Unknown plan'))->toThrow(CustomerChangeRefused::class, 'does not exist');

        expect(consoleSubscriptionOf($this->acme)->plan_id)->toBe(consolePlanId('starter'))
            ->and(AuditEntry::query()->where('event', 'subscription.plan_changed')->exists())->toBeFalse();
    });

    it('is not offered before the organization has a subscription', function () {
        $new = Organization::factory()->create(['name' => 'New Homes']);
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $new->id])
            ->assertActionHidden('changePlan')
            ->assertActionHidden('cancelSubscription')
            ->assertActionHidden('reactivateSubscription');
        expect(fn () => app(CustomerSubscriptions::class)->changePlan($operator, $new, consolePlanId('growth'), BillingCycle::Monthly, 'No subscription'))
            ->toThrow(CustomerChangeRefused::class, 'has no subscription yet');
    });
});

describe('cancel and reactivate the subscription', function () {
    it('acceptance: cancels with a reason, closes the organization and emails the owners after the commit', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionVisible('cancelSubscription')
            ->assertActionHidden('reactivateSubscription')
            ->callAction('cancelSubscription', data: ['reason' => 'Customer closed the business', 'note_to_owner' => 'Thank you for using GoProperty.'])
            ->assertHasNoActionErrors()
            ->assertNotified('The subscription of Acme Homes is cancelled.')
            ->assertActionHidden('cancelSubscription')
            ->assertActionVisible('reactivateSubscription');

        $subscription = consoleSubscriptionOf($this->acme);
        $acme = $this->acme->fresh();
        expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($subscription->ended_at)->not->toBeNull()
            ->and($subscription->organization_suspended)->toBeTrue()
            ->and($acme?->status)->toBe(OrganizationStatus::Cancelled)
            ->and($acme?->status_before_suspension)->toBe(OrganizationStatus::Active)
            ->and($acme?->status->blocksWrites())->toBeTrue();

        $entry = AuditEntry::query()->where('event', 'subscription.cancelled')->sole();
        expect($entry->causer_id)->toBe($operator->id)
            ->and($entry->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and($entry->reason)->toBe('Customer closed the business')
            ->and($entry->properties['old'] ?? null)->toEqual(['status' => 'active'])
            ->and($entry->properties['organization_status'] ?? null)->toEqual(['from' => 'active', 'to' => 'cancelled']);

        Notification::assertSentTo($this->owner, SubscriptionCancelledNotice::class, function (SubscriptionCancelledNotice $notice): bool {
            $mail = $notice->toMail($this->owner);
            $text = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

            return $notice->queue === 'notifications'
                && $notice->afterCommit === true
                && str_contains($mail->subject, 'Acme Homes is cancelled')
                && str_contains($text, 'Thank you for using GoProperty.')
                && str_contains($text, 'view and export')
                && ! str_contains($text, 'closed the business');
        });
        Notification::assertNotSentTo($this->agent, SubscriptionCancelledNotice::class);
    });

    it('cancels from trial, active and suspended, the lifecycle\'s own moves', function (string $status) {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $service = app(SubscriptionService::class);
        $organization = Organization::factory()->create(['name' => 'Status Homes', 'status' => OrganizationStatus::Active]);
        $status === 'trialing' ? startTrial($organization) : activeSubscription($organization);
        inOrganization($organization, function () use ($service, $status): void {
            foreach (['past_due' => ['markPastDue'], 'grace' => ['markPastDue', 'startGrace'], 'suspended' => ['markPastDue', 'startGrace', 'suspend']][$status] ?? [] as $step) {
                $service->{$step}();
            }
        });

        expect(Gate::forUser($operator)->allows('cancelSubscription', consoleCustomerOf($organization)))->toBeTrue();
        app(CustomerSubscriptions::class)->cancel($operator, $organization, 'Closing the account');

        expect(consoleSubscriptionOf($organization)->status)->toBe(SubscriptionStatus::Cancelled)
            ->and($organization->fresh()?->status)->toBe(OrganizationStatus::Cancelled)
            ->and(Gate::forUser($operator)->allows('cancelSubscription', consoleCustomerOf($organization)))->toBeFalse()
            ->and(fn () => app(CustomerSubscriptions::class)->cancel($operator, $organization, 'Again'))->toThrow(CustomerChangeRefused::class, 'already cancelled');
    })->with(['trialing', 'active', 'suspended']);

    it('does not cancel while past due or in grace: the lifecycle has no such move (the dunning ladder carries on)', function (string $status) {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $service = app(SubscriptionService::class);
        $organization = Organization::factory()->create(['name' => 'Status Homes', 'status' => OrganizationStatus::Active]);
        activeSubscription($organization);
        inOrganization($organization, function () use ($service, $status): void {
            foreach (['past_due' => ['markPastDue'], 'grace' => ['markPastDue', 'startGrace']][$status] as $step) {
                $service->{$step}();
            }
        });

        expect(Gate::forUser($operator)->allows('cancelSubscription', consoleCustomerOf($organization)))->toBeFalse()
            ->and(fn () => app(CustomerSubscriptions::class)->cancel($operator, $organization, 'Closing the account'))->toThrow(CustomerChangeRefused::class)
            ->and(consoleSubscriptionOf($organization)->status->value)->toBe($status)
            ->and($organization->fresh()?->status)->toBe(OrganizationStatus::Active);
    })->with(['past_due', 'grace']);

    it('acceptance: reactivates a cancelled subscription, reopening the organization the subscription closed, audited and emailed', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        app(CustomerSubscriptions::class)->cancel($operator, $this->acme, 'Customer closed the business');
        Notification::fake();
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('reactivateSubscription', data: ['reason' => 'Customer reopened and paid', 'note_to_owner' => 'Welcome back.'])
            ->assertHasNoActionErrors()
            ->assertNotified('The subscription of Acme Homes is active again.')
            ->assertActionHidden('reactivateSubscription')
            ->assertActionVisible('cancelSubscription');

        $subscription = consoleSubscriptionOf($this->acme);
        $acme = $this->acme->fresh();
        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->ended_at)->toBeNull()
            ->and($subscription->organization_suspended)->toBeFalse()
            ->and($acme?->status)->toBe(OrganizationStatus::Active)
            ->and($acme?->suspended_at)->toBeNull();

        $entry = AuditEntry::query()->where('event', 'subscription.active')->latest('id')->firstOrFail();
        expect($entry->causer_id)->toBe($operator->id)
            ->and($entry->reason)->toBe('Customer reopened and paid')
            ->and($entry->properties['old'] ?? null)->toEqual(['status' => 'cancelled'])
            ->and($entry->properties['organization_status'] ?? null)->toEqual(['from' => 'cancelled', 'to' => 'active']);

        Notification::assertSentTo($this->owner, SubscriptionReactivatedNotice::class, function (SubscriptionReactivatedNotice $notice): bool {
            $mail = $notice->toMail($this->owner);
            $text = implode(' ', $mail->introLines);

            return $notice->reopened
                && $notice->queue === 'notifications'
                && str_contains($text, 'Your team can work as before.')
                && str_contains($text, 'Welcome back.')
                && ! str_contains($text, 'reopened and paid');
        });
        Notification::assertNotSentTo($this->agent, SubscriptionReactivatedNotice::class);
    });

    it('leaves a suspension by staff alone: the organization is restored only when the subscription closed it', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        app(OrganizationSuspension::class)->suspend($operator, $this->acme, 'Chargeback under review');

        app(CustomerSubscriptions::class)->cancel($operator, $this->acme, 'Customer asked to stop');
        expect($this->acme->fresh()?->status)->toBe(OrganizationStatus::Suspended)
            ->and(consoleSubscriptionOf($this->acme)->organization_suspended)->toBeFalse();

        app(CustomerSubscriptions::class)->reactivate($operator, $this->acme, 'Customer asked to continue');
        $acme = $this->acme->fresh();
        expect(consoleSubscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active)
            ->and($acme?->status)->toBe(OrganizationStatus::Suspended)
            ->and($acme?->status_before_suspension)->toBe(OrganizationStatus::Active);
        Notification::assertSentTo($this->owner, SubscriptionReactivatedNotice::class, fn (SubscriptionReactivatedNotice $notice): bool => ! $notice->reopened
            && ! str_contains(implode(' ', $notice->toMail($this->owner)->introLines), 'work as before'));

        // Staff lift their own suspension.
        app(OrganizationSuspension::class)->reactivate($operator, $this->acme, 'Chargeback settled');
        expect($this->acme->fresh()?->status)->toBe(OrganizationStatus::Active);
    });

    it('reactivates only a cancelled subscription and requires a reason', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $service = app(CustomerSubscriptions::class);

        expect(Gate::forUser($operator)->allows('reactivateSubscription', consoleCustomerOf($this->acme)))->toBeFalse()
            ->and(fn () => $service->reactivate($operator, $this->acme, 'Nothing to do'))->toThrow(CustomerChangeRefused::class, 'Only a cancelled subscription')
            ->and(fn () => $service->cancel($operator, $this->acme, 'no'))->toThrow(CustomerChangeRefused::class, 'at least 3');

        $this->actingAs($operator, 'staff');
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('cancelSubscription', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        expect(consoleSubscriptionOf($this->acme)->status)->toBe(SubscriptionStatus::Active);
        Notification::assertNotSentTo($this->owner, SubscriptionCancelledNotice::class);
    });
});

describe('who may change subscriptions', function () {
    it('offers Change plan, Cancel and Reactivate subscription to operations-manager and super-admin', function (string $role) {
        $staff = staffNamed('Tunde', [$role]);
        $this->actingAs($staff, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionVisible('changePlan')
            ->assertActionVisible('cancelSubscription')
            ->assertActionHidden('reactivateSubscription');

        app(CustomerSubscriptions::class)->cancel($staff, $this->acme, 'Allowed for this role');
        expect(Gate::forUser($staff)->allows('reactivateSubscription', consoleCustomerOf($this->acme)))->toBeTrue();
    })->with(['operations-manager', 'super-admin']);

    it('hides and refuses them for every other role that opens the page', function (string $role) {
        $staff = staffNamed('Sade', [$role]);
        $this->actingAs($staff, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionHidden('changePlan')
            ->assertActionHidden('cancelSubscription')
            ->assertActionHidden('reactivateSubscription')
            // A direct call mounts nothing.
            ->call('mountAction', 'changePlan')
            ->assertSet('mountedActions', [])
            ->call('mountAction', 'cancelSubscription')
            ->assertSet('mountedActions', [])
            ->call('callMountedAction');

        expect(consoleSubscriptionOf($this->acme)->only(['plan_id', 'status']))->toEqual(['plan_id' => consolePlanId('starter'), 'status' => SubscriptionStatus::Active]);
        expectSubscriptionMovesRefused($this, $staff);
    })->with(['support-agent', 'finance-admin', 'compliance-officer']);

    it('refuses them to staff who cannot open Customers', function (string $role) {
        $staff = staffNamed('Sade', [$role]);

        $this->actingAs($staff, 'staff')->get($this->console.'/customers/'.$this->acme->id)->assertForbidden();
        expectSubscriptionMovesRefused($this, $staff);
    })->with(['analyst', 'technical-admin', 'content-manager']);

    it('gives a super-admin without 2FA nothing', function () {
        $staff = staffMember(['name' => 'Kunle', 'email' => 'kunle@goproperty.ng'], withTwoFactor: false);

        expectSubscriptionMovesRefused($this, $staff);
    });
});

/** Every ability is refused, and every service call too, with nothing changed. */
function expectSubscriptionMovesRefused(mixed $test, PlatformStaff $staff): void
{
    $customer = consoleCustomerOf($test->acme);
    $service = app(CustomerSubscriptions::class);

    foreach (['changePlan', 'cancelSubscription', 'reactivateSubscription'] as $ability) {
        expect(Gate::forUser($staff)->allows($ability, $customer))->toBeFalse();
    }

    expect(fn () => $service->changePlan($staff, $test->acme, consolePlanId('growth'), BillingCycle::Monthly, 'Trying anyway'))->toThrow(CustomerChangeRefused::class, 'permission')
        ->and(fn () => $service->cancel($staff, $test->acme, 'Trying anyway'))->toThrow(CustomerChangeRefused::class, 'permission');

    app(CustomerSubscriptions::class)->cancel(staffNamed('Root'.$staff->id, ['super-admin']), $test->acme, 'Set up the reactivation probe');
    Notification::fake();

    expect(Gate::forUser($staff)->allows('reactivateSubscription', consoleCustomerOf($test->acme)))->toBeFalse()
        ->and(fn () => $service->reactivate($staff, $test->acme, 'Trying anyway'))->toThrow(CustomerChangeRefused::class, 'permission')
        ->and(consoleSubscriptionOf($test->acme)->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(AuditEntry::query()->where('causer_id', $staff->id)->where('causer_type', (new PlatformStaff)->getMorphClass())->where('log_name', 'subscriptions')->exists())->toBeFalse();
    Notification::assertNotSentTo($test->owner, SubscriptionReactivatedNotice::class);
}
