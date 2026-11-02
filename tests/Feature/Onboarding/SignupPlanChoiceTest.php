<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

/*
 * P06-11: the plan and billing period picked on the pricing page carry
 * through signup (App\Marketing\SignupPlanChoice): the account step shows
 * them, the wizard's plan step starts with them selected, and saving it
 * starts the trial on that plan and cycle — no card, no payment
 * (ONBOARDING_COMPLIANCE.md § 2 step 4; the product owner's choice of
 * 2026-11-02).
 */

beforeEach(function () {
    seedOrganizationRoles();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Africa/Lagos'));
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

function subscriptionFor(Organization $organization): Subscription
{
    return inOrganization($organization, fn (): Subscription => Subscription::query()->sole());
}

/** The wizard's plan step as its Livewire snapshot holds it. */
function planStepState(Organization $organization): array
{
    $snapshot = LivewireHttp::snapshot(test()->get("/o/{$organization->slug}/setup")->assertOk()->assertSee('Choose a plan'));
    $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];

    return ['plan' => $data['plan'], 'cycle' => $data['cycle']];
}

function signupToPlanStep(): Organization
{
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);

    return $organization;
}

it('starts the trial on the plan and yearly billing picked on the pricing page', function () {
    $this->get('/register?plan=scale&cycle=annual')->assertOk()
        ->assertSee('Scale plan, billed yearly')
        ->assertSee('₦850,000 a year for 200 units, plus VAT, after your free trial.');

    $organization = signupToPlanStep();
    expect(planStepState($organization))->toBe(['plan' => 'scale', 'cycle' => 'annual']);
    $this->get('/o/adeyemi-realty/setup')->assertSee('₦850,000<small>/year</small>', false);

    signupStep($organization, 'savePlan');

    $subscription = subscriptionFor($organization);
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan_id)->toBe(SubscriptionPlan::query()->where('key', 'scale')->value('id'))
        ->and($subscription->billing_cycle)->toBe(BillingCycle::Annual)
        ->and($subscription->trial_ends_at?->toDateTimeString())->toBe('2026-11-16 08:00:00')
        ->and($organization->fresh()->signup_plan_key)->toBe('scale')
        // The choice has done its job: it is no longer in the session.
        ->and(session()->has('signup.plan'))->toBeFalse();

    // Settings → Subscription says how it will be billed.
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');
    $this->get('/o/adeyemi-realty/settings/subscription')->assertOk()->assertSee('Billed yearly');
});

it('lets the plan step change the plan and the billing period before the trial starts', function () {
    $this->get('/register?plan=growth&cycle=monthly')->assertOk()->assertSee('Growth plan, billed monthly');
    $organization = signupToPlanStep();
    expect(planStepState($organization))->toBe(['plan' => 'growth', 'cycle' => 'monthly']);

    signupStep($organization, 'savePlan', ['plan' => 'starter', 'cycle' => 'annual']);
    expect(subscriptionFor($organization)->billing_cycle)->toBe(BillingCycle::Annual);

    // Back to the plan step: it shows what was saved; monthly again keeps the trial's end.
    signupStep($organization, 'back');
    expect(planStepState($organization))->toBe(['plan' => 'starter', 'cycle' => 'annual']);
    $this->travel(1)->days();
    signupStep($organization, 'savePlan', ['cycle' => 'monthly']);

    $subscription = subscriptionFor($organization);
    expect($subscription->billing_cycle)->toBe(BillingCycle::Monthly)
        ->and($subscription->plan_id)->toBe(SubscriptionPlan::query()->where('key', 'starter')->value('id'))
        ->and($subscription->trial_ends_at?->toDateTimeString())->toBe('2026-11-16 08:00:00');
});

it('ignores a link for a plan that is unknown, priced on request or no longer offered', function (string $query) {
    SubscriptionPlan::query()->where('key', 'scale')->update(['is_active' => false]);

    $this->get('/register?'.$query)->assertOk()->assertDontSee('data-test="chosen-plan"', false);

    // The wizard proposes its usual plan, monthly.
    expect(planStepState(signupToPlanStep()))->toBe(['plan' => 'growth', 'cycle' => 'monthly']);
})->with([
    'unknown' => ['plan=platinum&cycle=annual'],
    'Enterprise' => ['plan=enterprise&cycle=annual'],
    'withdrawn' => ['plan=scale&cycle=annual'],
    'no plan' => ['cycle=annual'],
]);

it('reads anything but a yearly period as monthly, and refuses an unknown one in the wizard', function () {
    $this->get('/register?plan=starter&cycle=weekly')->assertOk()->assertSee('Starter plan, billed monthly');
    $organization = signupToPlanStep();

    $response = signupStep($organization, 'savePlan', ['cycle' => 'weekly']);

    expect(LivewireHttp::errors($response))->toHaveKey('cycle')
        ->and(inOrganization($organization, fn (): int => Subscription::query()->count()))->toBe(0);
});
