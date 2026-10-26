<?php

declare(strict_types=1);

use App\Enums\ChecklistStatus;
use App\Enums\OnboardingStep;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\LivewireHttp;

/*
 * P01-02 acceptance (IMPLEMENTATION_PLAN.md): a new signup reaches the
 * dashboard as org-owner; leaving mid-wizard resumes at the same step;
 * property-owner signup sets platform_covered (ONBOARDING_COMPLIANCE.md § 2).
 * The wizard is driven over HTTP as the browser does, including Livewire
 * update requests (Tests\Support\LivewireHttp).
 */

beforeEach(function () {
    seedOrganizationRoles();
    Notification::fake();
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('takes a new signup to the dashboard as org-owner', function () {
    $this->get('/register')->assertOk()->assertSee('Start your free trial');
    $user = signUpAndVerify();

    $this->get('/dashboard?verified=1')->assertRedirect('/signup/organization');
    $organization = signupOrganization();

    expect($organization->status)->toBe(OrganizationStatus::Onboarding)
        ->and($user->roleNamesIn($organization))->toBe([OrganizationRole::Owner->value]);

    $this->get('/o/adeyemi-realty')->assertRedirect('/o/adeyemi-realty/setup');
    $this->get('/o/adeyemi-realty/setup')->assertOk()->assertSee('Choose your Transaction Tier');

    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);
    $this->get('/o/adeyemi-realty/setup')->assertSee('Choose a plan');

    signupStep($organization, 'savePlan', ['plan' => 'growth']);
    $this->get('/o/adeyemi-realty/setup')->assertSee('How will tenants pay you?');

    signupStep($organization, 'setUpCollectionsLater');
    $this->get('/o/adeyemi-realty/setup')->assertSee('Add your properties');

    $finish = signupStep($organization, 'finish');
    expect(LivewireHttp::redirect($finish))->toBe(route('workspace.home', $organization));

    $this->get('/o/adeyemi-realty')->assertOk()
        ->assertSee('Adeyemi Realty')
        ->assertSee('Finish setting up')
        ->assertSee('2 of 5 done');

    $organization->refresh();
    expect($organization->status)->toBe(OrganizationStatus::Active)
        ->and($organization->transaction_tier)->toBe(TransactionTier::One)
        ->and($organization->signup_plan_key)->toBe('growth')
        ->and($organization->platform_covered)->toBeFalse()
        ->and($user->fresh()->hasRoleIn($organization, OrganizationRole::Owner))->toBeTrue()
        ->and(onboardingOf($organization)->isComplete())->toBeTrue();

    // Signing in again goes straight to the workspace.
    $this->post('/logout');
    $this->post('/login', ['identifier' => 'ada@adeyemi.ng', 'password' => 'a-long-password-1'])->assertRedirect('/o/adeyemi-realty');
});

it('resumes at the same step after leaving mid-wizard', function () {
    $user = signUpAndVerify();

    // Left after confirming the email: signing in again continues at the organization step.
    signInAgain()->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect('/signup/organization');

    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '200,000,000', 'tier' => 2]);

    // Left at the plan step: the next sign-in, in a fresh session, lands there again.
    signInAgain()->assertRedirect('/o/adeyemi-realty');
    $this->get('/o/adeyemi-realty')->assertRedirect('/o/adeyemi-realty/setup');
    $this->get('/o/adeyemi-realty/setup')->assertOk()->assertSee('Choose a plan');
    expect(onboardingOf($organization)->step)->toBe(OnboardingStep::Plan);

    // Went back to the Tier step and left: it comes back there, with the answers kept.
    signupStep($organization, 'back');
    signInAgain();
    $page = $this->get('/o/adeyemi-realty/setup')->assertOk()->assertSee('Choose your Transaction Tier');
    expect(snapshotData(LivewireHttp::snapshot($page)))
        ->annualVolume->toBe('200,000,000')
        ->tier->toBe(2);

    // Back once more, to the organization details, which can still be changed.
    signupStep($organization, 'back');
    $this->get('/o/adeyemi-realty/setup')->assertSee('Your organization')->assertSee('localhost/o/adeyemi-realty');
    signupStep($organization, 'saveOrganization', ['form.name' => 'Adeyemi Realty Ltd']);
    expect($organization->fresh()->name)->toBe('Adeyemi Realty Ltd')
        ->and(onboardingOf($organization)->step)->toBe(OnboardingStep::Tier)
        ->and($user->fresh()->hasRoleIn($organization, OrganizationRole::Owner))->toBeTrue();
});

it('resumes at email confirmation when the link was not opened', function () {
    $this->post('/register', ['name' => 'Ada', 'email' => 'ada@adeyemi.ng', 'phone' => '08031234567', 'password' => 'a-long-password-1']);

    $this->post('/login', ['identifier' => 'ada@adeyemi.ng', 'password' => 'a-long-password-1'])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    $this->get('/signup/organization')->assertRedirect(route('verification.notice'));
});

it('sets platform_covered when a property owner signs up', function () {
    signUpAndVerify();
    $page = $this->get('/signup/organization');
    $typed = LivewireHttp::update($this, LivewireHttp::snapshot($page), ['form.type' => 'property_owner']);
    expect(LivewireHttp::html($typed))->toContain('your subscription is covered');

    $organization = signupOrganization(['type' => 'property_owner', 'name' => 'Bello Family Homes', 'slug' => 'bello-family-homes']);

    expect($organization->type)->toBe(OrganizationType::PropertyOwner)
        ->and($organization->platform_covered)->toBeTrue()
        ->and($organization->transaction_tier)->toBe(TransactionTier::One);

    signupStep($organization, 'saveTier', ['annualVolume' => '12,000,000', 'tier' => 1]);
    $this->get('/o/bello-family-homes/setup')->assertSee('Choose a plan')->assertSee('data-test="platform-covered-plan"', false);
});

it('leaves platform_covered off for agencies and hybrids', function (string $type) {
    signUpAndVerify();
    $organization = signupOrganization(['type' => $type]);

    expect($organization->platform_covered)->toBeFalse();
    $this->get('/o/adeyemi-realty/setup')->assertDontSee('platform-covered', false);
})->with(['agency', 'hybrid']);

it('follows a type changed during signup', function () {
    signUpAndVerify();
    $organization = signupOrganization(['type' => 'property_owner']);
    signupStep($organization, 'back');

    signupStep($organization, 'saveOrganization', ['form.type' => 'agency']);
    expect($organization->fresh()->platform_covered)->toBeFalse();

    signupStep($organization, 'back');
    signupStep($organization, 'saveOrganization', ['form.type' => 'property_owner']);
    expect($organization->fresh()->platform_covered)->toBeTrue();
});

it('makes Tier 1 active at once', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);

    $state = onboardingOf($organization);
    $tier = app(OrganizationContext::class)->run($organization, fn () => OnboardingChecklistItem::query()->where('item', 'tier')->firstOrFail());

    expect($state->requested_tier)->toBe(TransactionTier::One)
        ->and($state->declared_annual_volume_kobo?->kobo)->toBe(3_000_000_000)
        ->and($organization->fresh()->transaction_tier)->toBe(TransactionTier::One)
        ->and($tier->status)->toBe(ChecklistStatus::Done);
});

it('records a Tier 2 or 3 request while the organization works at Tier 1', function (int $requested) {
    signUpAndVerify();
    $organization = signupOrganization();

    $page = $this->get('/o/adeyemi-realty/setup');
    $typed = LivewireHttp::update($this, LivewireHttp::snapshot($page), ['annualVolume' => '200,000,000', 'tier' => $requested]);
    expect(LivewireHttp::html($typed))->toContain('needs a compliance review')->toContain('Suggested');

    signupStep($organization, 'saveTier', ['annualVolume' => '200,000,000', 'tier' => $requested]);

    $state = onboardingOf($organization);
    $tier = app(OrganizationContext::class)->run($organization, fn () => OnboardingChecklistItem::query()->where('item', 'tier')->firstOrFail());

    expect($state->requested_tier?->value)->toBe($requested)
        ->and($state->declared_annual_volume_kobo?->kobo)->toBe(20_000_000_000)
        ->and($organization->fresh()->transaction_tier)->toBe(TransactionTier::One)
        ->and($tier->status)->toBe(ChecklistStatus::Pending)
        ->and($tier->details)->toBe(['requested_tier' => $requested]);

    // The request is in the audit log, with who made it.
    $logged = Activity::query()->where('log_name', 'onboarding')->where('event', 'updated')->orderByDesc('id')->firstOrFail();
    expect($logged->attribute_changes['attributes'] ?? [])->toMatchArray(['requested_tier' => $requested, 'declared_annual_volume_kobo' => 20_000_000_000])
        ->and($logged->causer?->is(User::query()->where('email', 'ada@adeyemi.ng')->first()))->toBeTrue();

    signupStep($organization, 'savePlan', ['plan' => 'starter']);
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');

    $this->get('/o/adeyemi-realty')->assertOk()->assertSee("Tier {$requested} requested");
})->with([2, 3]);

it('refuses an amount that is not naira, or no tier', function (array $updates, string $field) {
    signUpAndVerify();
    $organization = signupOrganization();

    $response = signupStep($organization, 'saveTier', $updates);

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(onboardingOf($organization)->step)->toBe(OnboardingStep::Tier);
})->with([
    'words' => [['annualVolume' => 'fifty million', 'tier' => 1], 'annualVolume'],
    'negative' => [['annualVolume' => '-5,000', 'tier' => 1], 'annualVolume'],
    'empty' => [['annualVolume' => '', 'tier' => 1], 'annualVolume'],
    'no tier' => [['annualVolume' => '5,000,000'], 'tier'],
    'tier 4' => [['annualVolume' => '5,000,000', 'tier' => 4], 'tier'],
]);

it('stores the chosen plan and starts a 14-day trial once', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(9, 0));
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);

    $this->get('/o/adeyemi-realty/setup')->assertSee('14-day free trial');
    signupStep($organization, 'savePlan', ['plan' => 'growth']);

    $organization->refresh();
    expect($organization->signup_plan_key)->toBe('growth')
        ->and($organization->trial_ends_at?->toDateTimeString())->toBe('2026-11-16 09:00:00');

    // Changing the plan a day later does not restart the trial.
    $this->travel(1)->days();
    signupStep($organization, 'back');
    signupStep($organization, 'savePlan', ['plan' => 'starter']);

    $organization->refresh();
    expect($organization->signup_plan_key)->toBe('starter')
        ->and($organization->trial_ends_at?->toDateTimeString())->toBe('2026-11-16 09:00:00');
});

it('refuses a plan that is not in the catalogue', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);

    $response = signupStep($organization, 'savePlan', ['plan' => 'platinum']);

    expect(LivewireHttp::errors($response))->toHaveKey('plan')
        ->and($organization->fresh()->signup_plan_key)->toBeNull()
        ->and($organization->fresh()->trial_ends_at)->toBeNull();
});

it('records rent collection and properties as pending when put off', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '30,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan', ['plan' => 'starter']);
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');

    $items = app(OrganizationContext::class)->run($organization, fn () => OnboardingChecklistItem::query()->pluck('status', 'item')->map(fn ($status) => $status->value)->all());

    expect($items)->toEqual([
        'tier' => 'done',
        'plan' => 'done',
        'collections' => 'pending',
        'data' => 'pending',
        'branding' => 'pending',
    ]);

    $this->get('/o/adeyemi-realty')->assertOk()
        ->assertSee('data-item="collections" data-status="pending"', false)
        ->assertSee('data-item="data" data-status="pending"', false)
        ->assertSee('data-item="branding" data-status="pending"', false);
});
