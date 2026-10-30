<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Customers\OrganizationSuspension;
use App\Subscriptions\Events\SubscriptionStatusChanged;
use App\Subscriptions\Jobs\EndTrials;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-01b: subscription lifecycle states through SubscriptionService
 * (BILLING_PAYMENTS.md § 2; AGENT.md § 3 rule 5; docs/plans/P06-subtasks.md
 * defaults 8–12): the trial started by the signup wizard, its end, every
 * allowed move and no other, the organization made read-only by
 * suspension and cancellation and restored on payment (never lifting a
 * staff suspension), and plan changes.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

function service(): SubscriptionService
{
    return app(SubscriptionService::class);
}

function acmeSubscription(Organization $organization): Subscription
{
    return inOrganization($organization, fn (): Subscription => Subscription::query()->sole());
}

it('starts the trial at the signup wizard\'s plan step, and keeps its end when the plan changes', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan', ['plan' => 'growth'])->assertOk();

    $subscription = acmeSubscription($organization);
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->key)->toBe('growth')
        ->and($subscription->trial_ends_at?->toIso8601String())->toBe(now()->addDays(14)->toIso8601String())
        ->and($subscription->trialDaysLeft())->toBe(14);

    $this->travel(3)->days();
    signupStep($organization, 'back');
    signupStep($organization, 'savePlan', ['plan' => 'starter'])->assertOk();

    $subscription = acmeSubscription($organization);
    expect($subscription->plan->key)->toBe('starter')
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at?->toDateString())->toBe('2026-11-16')
        ->and($subscription->trialDaysLeft())->toBe(11)
        ->and(AuditEntry::query()->where('log_name', 'subscriptions')->pluck('event')->all())->toBe(['subscription.started', 'subscription.plan_changed']);
});

it('ends due trials hourly: active from the trial\'s last day, once, the event dispatched after the commit', function () {
    Event::fake([SubscriptionStatusChanged::class]);
    startTrial($this->acme, 'growth', 14);
    $later = Organization::factory()->create();
    startTrial($later, 'starter', 20);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Africa/Lagos'));
    (new EndTrials)->handle(app(OrganizationContext::class), service());
    (new EndTrials)->handle(app(OrganizationContext::class), service());

    $subscription = acmeSubscription($this->acme);
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_start?->toDateString())->toBe('2026-11-16')
        ->and($subscription->activated_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and(acmeSubscription($later)->status)->toBe(SubscriptionStatus::Trialing);

    // Two trials started, one ended (once, though the job ran twice).
    $events = Event::dispatched(SubscriptionStatusChanged::class)->map(fn (array $args): string => $args[0]->organizationId.':'.($args[0]->from->value ?? 'new').'→'.$args[0]->to->value)->all();
    expect($events)->toBe([$this->acme->id.':new→trialing', $later->id.':new→trialing', $this->acme->id.':trialing→active']);

    // Scheduled hourly on the billing queue, in-process (no Schedule::command).
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'subscriptions-trials');
    expect($event)->toBeInstanceOf(CallbackEvent::class)->and($event->expression)->toBe('15 * * * *');

    // A trial not yet over is refused.
    expect(fn () => inOrganization($later, fn () => service()->endTrial()))->toThrow(SubscriptionChangeRefused::class, 'The trial has not ended yet.');
});

it('allows exactly the lifecycle\'s moves', function () {
    $moves = [
        SubscriptionStatus::Trialing->value => ['active', 'cancelled'],
        SubscriptionStatus::Active->value => ['past_due', 'cancelled'],
        SubscriptionStatus::PastDue->value => ['active', 'grace'],
        SubscriptionStatus::Grace->value => ['active', 'suspended'],
        SubscriptionStatus::Suspended->value => ['active', 'cancelled'],
        SubscriptionStatus::Cancelled->value => ['active'],
    ];

    foreach (SubscriptionStatus::cases() as $from) {
        expect(array_map(fn (SubscriptionStatus $to): string => $to->value, $from->next()))->toBe($moves[$from->value]);
    }

    activeSubscription($this->acme, 'starter');
    expect(fn () => inOrganization($this->acme, fn () => service()->suspend()))->toThrow(SubscriptionChangeRefused::class, 'A subscription that is Active cannot become Suspended.')
        ->and(fn () => inOrganization($this->acme, fn () => service()->startGrace()))->toThrow(SubscriptionChangeRefused::class);
});

it('makes the organization read-only when suspended or cancelled and restores it when paid, audited once', function () {
    activeSubscription($this->acme, 'growth');
    $writes = app(OrganizationWriteGuard::class);

    inOrganization($this->acme, function (): void {
        service()->markPastDue();
        service()->startGrace();
    });
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Active);

    inOrganization($this->acme, fn () => service()->suspend());
    $acme = $this->acme->fresh();
    expect($acme->status)->toBe(OrganizationStatus::Suspended)
        ->and($acme->status_before_suspension)->toBe(OrganizationStatus::Active)
        ->and(acmeSubscription($this->acme)->organization_suspended)->toBeTrue()
        ->and(inOrganization($acme, fn () => $writes->blockedOrganization()?->id))->toBe($acme->id);

    inOrganization($this->acme, fn () => service()->activate(null, 'Paid invoice PR-2026-000001'));
    $acme = $this->acme->fresh();
    expect($acme->status)->toBe(OrganizationStatus::Active)
        ->and($acme->suspended_at)->toBeNull()
        ->and(acmeSubscription($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and(acmeSubscription($this->acme)->organization_suspended)->toBeFalse();

    $entry = AuditEntry::query()->where('event', 'subscription.suspended')->sole();
    // toEqual: MySQL's JSON column may reorder the keys.
    expect($entry->properties['organization_status'])->toEqual(['from' => 'active', 'to' => 'suspended'])
        ->and(AuditEntry::query()->where('log_name', 'customers')->count())->toBe(0);

    // Cancelled: closed, data kept; reactivated and paid: open again.
    inOrganization($this->acme, fn () => service()->cancel($this->owner, 'Owner closed the agency'));
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Cancelled)
        ->and(acmeSubscription($this->acme)->ended_at)->not->toBeNull();
    inOrganization($this->acme, fn () => service()->activate(null, 'Came back and paid'));
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Active)
        ->and(acmeSubscription($this->acme)->ended_at)->toBeNull();
});

it('never lifts a staff suspension on payment, and returns a restricted organization to restricted', function () {
    seedStaffRoles();
    activeSubscription($this->acme, 'growth');
    inOrganization($this->acme, fn () => [service()->markPastDue(), service()->startGrace()]);

    app(OrganizationSuspension::class)->suspend(staffNamed('Tunde', ['operations-manager']), $this->acme, 'Chargeback investigation');
    inOrganization($this->acme, fn () => service()->suspend());
    expect(acmeSubscription($this->acme)->organization_suspended)->toBeFalse();

    inOrganization($this->acme, fn () => service()->activate(null, 'Paid'));
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended);

    $restricted = Organization::factory()->create(['status' => OrganizationStatus::Restricted]);
    activeSubscription($restricted, 'starter');
    inOrganization($restricted, fn () => [service()->markPastDue(), service()->startGrace(), service()->suspend()]);
    expect($restricted->fresh()->status)->toBe(OrganizationStatus::Suspended);
    inOrganization($restricted, fn () => service()->activate(null, 'Paid'));
    expect($restricted->fresh()->status)->toBe(OrganizationStatus::Restricted);
});

it('changes plan at once for a customer, but not to a plan whose limits it is over, nor while read-only', function () {
    startTrial($this->acme, 'growth');
    foreach (range(1, 3) as $n) {
        joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    }

    // Four members: Starter allows 3.
    expect(fn () => inOrganization($this->acme, fn () => service()->changePlan($this->owner, planNamed('starter'))))
        ->toThrow(SubscriptionChangeRefused::class, 'Starter includes 3 staff users and Acme Homes has 4 (members and invitations still waiting). Remove 1 first.')
        ->and(fn () => inOrganization($this->acme, fn () => service()->changePlan($this->owner, planNamed('growth'))))
        ->toThrow(SubscriptionChangeRefused::class, 'Acme Homes is already on Growth.')
        ->and(fn () => inOrganization($this->acme, fn () => service()->changePlan($this->owner, planNamed('enterprise'))))
        ->toThrow(SubscriptionChangeRefused::class, 'Enterprise is priced for each customer');

    inOrganization($this->acme, fn () => service()->changePlan($this->owner, planNamed('scale')));
    expect(acmeSubscription($this->acme)->plan->key)->toBe('scale')
        ->and(acmeSubscription($this->acme)->status)->toBe(SubscriptionStatus::Trialing);

    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect(fn () => inOrganization($this->acme->fresh(), fn () => service()->changePlan($this->owner, planNamed('growth'))))
        ->toThrow(SubscriptionChangeRefused::class, 'only paying the subscription is possible');
});

it('starts subscriptions for organizations that chose a plan before P06-01, once', function () {
    $chose = Organization::factory()->create(['signup_plan_key' => 'growth', 'trial_ends_at' => now()->addDays(5)]);
    $none = Organization::factory()->create(['signup_plan_key' => null]);

    $migration = require base_path('database/migrations/2026_12_04_060202_start_subscriptions_for_chosen_plans.php');
    $migration->up();
    $migration->up();

    $subscription = acmeSubscription($chose);
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->key)->toBe('growth')
        ->and($subscription->trial_ends_at?->toDateString())->toBe(now()->addDays(5)->toDateString())
        ->and(inOrganization($none, fn () => Subscription::query()->count()))->toBe(0);
});

it('never deletes a subscription', function () {
    $subscription = startTrial($this->acme);
    expect(fn () => inOrganization($this->acme, fn () => $subscription->delete()))->toThrow(LogicException::class, 'Subscriptions are never deleted');
});
