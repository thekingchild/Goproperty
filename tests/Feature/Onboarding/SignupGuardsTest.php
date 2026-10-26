<?php

declare(strict_types=1);

use App\Enums\ChecklistItem;
use App\Enums\OnboardingStep;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Onboarding\OrganizationDetails;
use App\Onboarding\OrganizationSignup;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

/*
 * Signup wizard (ONBOARDING_COMPLIANCE.md § 2): the organization step's
 * rules, who may see and save each step (OrganizationPolicy), the
 * organization context on Livewire updates inside /o/{slug}, and the
 * dashboard checklist that later features complete.
 */

beforeEach(function () {
    seedOrganizationRoles();
    Notification::fake();
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('suggests the web address from the name until one is typed', function () {
    Organization::factory()->create(['slug' => 'adeyemi-realty']);
    signUpAndVerify();
    $page = $this->get('/signup/organization');

    $named = LivewireHttp::update($this, LivewireHttp::snapshot($page), ['form.name' => 'Adeyemi Realty']);
    expect(snapshotData(LivewireHttp::next($named))['slug'])->toBe('adeyemi-realty-2');

    $typed = LivewireHttp::update($this, LivewireHttp::next($named), ['slug' => 'Ade-Homes']);
    $renamed = LivewireHttp::update($this, LivewireHttp::next($typed), ['form.name' => 'Something Else']);
    expect(snapshotData(LivewireHttp::next($renamed))['slug'])->toBe('ade-homes');
});

it('refuses a web address that is taken, was used before, reserved or malformed', function (string $slug) {
    Organization::factory()->create(['slug' => 'taken-realty']);
    Organization::factory()->create(['slug' => 'renamed-realty', 'previous_slugs' => ['old-realty']]);
    signUpAndVerify();
    $page = $this->get('/signup/organization');

    $response = LivewireHttp::update($this, LivewireHttp::snapshot($page), [
        'form.name' => 'Ade Realty', 'form.state' => 'Lagos', 'slug' => $slug,
    ], [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey('slug')
        ->and(LivewireHttp::redirect($response))->toBeNull()
        ->and(Organization::query()->count())->toBe(2);
})->with(['taken-realty', 'old-realty', 'support', 'goproperty', 'ab', 'Bad Slug!', '-ade-', 'ade--realty']);

it('refuses missing or unknown organization details', function (array $updates, string $field) {
    signUpAndVerify();
    $page = $this->get('/signup/organization');

    $response = LivewireHttp::update($this, LivewireHttp::snapshot($page), $updates + [
        'form.name' => 'Ade Realty', 'form.state' => 'Lagos', 'slug' => 'ade-realty',
    ], [['save']]);

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(Organization::query()->count())->toBe(0);
})->with([
    'no name' => [['form.name' => ''], 'form.name'],
    'unknown type' => [['form.type' => 'landlord'], 'form.type'],
    'unknown state' => [['form.state' => 'Atlantis'], 'form.state'],
    'odd CAC number' => [['form.cacNumber' => 'not a number'], 'form.cacNumber'],
]);

it('keeps the CAC number encrypted', function () {
    signUpAndVerify();
    $page = $this->get('/signup/organization');
    LivewireHttp::update($this, LivewireHttp::snapshot($page), [
        'form.name' => 'Ade Realty', 'form.state' => 'Lagos', 'slug' => 'ade-realty', 'form.cacNumber' => 'rc 1234567',
    ], [['save']]);

    $organization = Organization::query()->where('slug', 'ade-realty')->firstOrFail();
    $stored = DB::table('organizations')->where('id', $organization->id)->value('cac_number');

    expect($organization->cac_number)->toBe('RC 1234567')
        ->and($stored)->not->toContain('1234567');
});

it('asks for a mobile number when signup could not keep one', function () {
    User::factory()->create(['phone' => '+2348031234567']);
    signUpAndVerify();
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    expect($user->phone)->toBeNull();

    $page = $this->get('/signup/organization')->assertSee('Your mobile number');
    $fields = ['form.name' => 'Ade Realty', 'form.state' => 'Lagos', 'slug' => 'ade-realty'];

    $taken = LivewireHttp::update($this, LivewireHttp::snapshot($page), $fields + ['phone' => '0803 123 4567'], [['save']]);
    expect(LivewireHttp::errors($taken))->toHaveKey('phone');

    $landline = LivewireHttp::update($this, LivewireHttp::snapshot($page), $fields + ['phone' => '01 234 5678'], [['save']]);
    expect(LivewireHttp::errors($landline))->toHaveKey('phone');

    $saved = LivewireHttp::update($this, LivewireHttp::snapshot($page), $fields + ['phone' => '0905 555 0101'], [['save']]);
    expect(LivewireHttp::redirect($saved))->toBe(route('workspace.setup', 'ade-realty'))
        ->and($user->fresh()->phone)->toBe('+2349055550101');
});

it('runs one organization signup at a time per user', function () {
    signUpAndVerify();
    $organization = signupOrganization();

    $this->get('/signup/organization')->assertRedirect(route('workspace.setup', $organization));

    // A second tab that still shows the form adds nothing.
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    $again = app(OrganizationSignup::class)->start($user, new OrganizationDetails(OrganizationType::Agency, 'Second Try', 'Lagos', null, null), 'second-try');

    expect($again->is($organization))->toBeTrue()
        ->and(Organization::query()->count())->toBe(1);
});

it('lets a user sign up a new organization once the first is set up', function () {
    signUpAndVerify();
    $first = signupOrganization();
    signupStep($first, 'saveTier', ['annualVolume' => '1,000,000', 'tier' => 1]);
    signupStep($first, 'savePlan', ['plan' => 'starter']);
    signupStep($first, 'setUpCollectionsLater');
    signupStep($first, 'finish');

    $second = signupOrganization(['name' => 'Ade Homes', 'slug' => 'ade-homes']);

    expect(User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail()->roleNamesIn($second))->toBe(['org-owner']);
});

it('shows each step only to members allowed to complete it', function (OrganizationRole $role) {
    signUpAndVerify();
    $organization = signupOrganization();
    $colleague = joinAs(User::factory()->create(), $organization, $role);
    $this->post('/logout');

    $this->actingAs($colleague);
    $this->get('/o/adeyemi-realty/setup')->assertForbidden();
    // The workspace itself opens normally, without being sent to the wizard.
    $this->get('/o/adeyemi-realty')->assertOk()->assertSee('Nothing to do yet');
})->with([OrganizationRole::Admin, OrganizationRole::Agent]);

it('answers 404 to strangers and members who do not open the workspace', function (?OrganizationRole $role) {
    signUpAndVerify();
    $organization = signupOrganization();
    $other = User::factory()->create();

    if ($role !== null) {
        joinAs($other, $organization, $role);
    }

    $this->post('/logout');
    $this->actingAs($other)->get('/o/adeyemi-realty/setup')->assertNotFound();
})->with(['stranger' => [null], 'tenant' => [OrganizationRole::Tenant], 'property owner' => [OrganizationRole::PropertyOwner]]);

it('sets the organization context on Livewire updates, and refuses them to non-members', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    $snapshot = LivewireHttp::snapshot($this->get('/o/adeyemi-realty/setup'));

    // The owner's update runs in the organization (it saves).
    $saved = LivewireHttp::update($this, $snapshot, ['annualVolume' => '5,000,000', 'tier' => 1], [['saveTier']])->assertOk();
    expect(onboardingOf($organization)->step)->toBe(OnboardingStep::Plan)
        ->and(LivewireHttp::html($saved))->toContain('Choose a plan');

    // The same snapshot replayed by someone else is refused like the page.
    $this->post('/logout');
    $this->actingAs(User::factory()->create());
    LivewireHttp::update($this, LivewireHttp::next($saved), [], [['back']])->assertNotFound();

    // An agent of the organization passes the context check but not the policy.
    $agent = joinAs(User::factory()->create(), $organization, OrganizationRole::Agent);
    $this->actingAs($agent);
    LivewireHttp::update($this, LivewireHttp::next($saved), ['plan' => 'starter'], [['savePlan']])->assertForbidden();

    expect(onboardingOf($organization)->step)->toBe(OnboardingStep::Plan)
        ->and($organization->fresh()->signup_plan_key)->toBeNull();
});

it('does not save a step twice from a stale tab', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    $stale = LivewireHttp::snapshot($this->get('/o/adeyemi-realty/setup'));

    signupStep($organization, 'saveTier', ['annualVolume' => '5,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan', ['plan' => 'starter']);

    $response = LivewireHttp::update($this, $stale, ['annualVolume' => '400,000,000', 'tier' => 2], [['saveTier']])->assertOk();

    expect(LivewireHttp::html($response))->toContain('already saved')->toContain('How will tenants pay you?')
        ->and(onboardingOf($organization)->requested_tier?->value)->toBe(1)
        ->and(onboardingOf($organization)->step)->toBe(OnboardingStep::Collections);
});

it('sends someone with no organization from the dashboard to signup', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/signup/organization');
});

it('keeps the dashboard for organizations made outside signup', function () {
    $user = joinAs(User::factory()->create(), Organization::factory()->create(['slug' => 'demo-realty']));

    $this->actingAs($user)->get('/o/demo-realty')->assertOk()->assertDontSee('data-test="setup-checklist"', false);
    $this->get('/o/demo-realty/setup')->assertRedirect('/o/demo-realty');
});

it('lets later features tick checklist items off until it disappears', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '5,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan', ['plan' => 'starter']);
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');
    $this->get('/o/adeyemi-realty')->assertSee('2 of 5 done')->assertSee('Coming soon');

    $checklist = app(OnboardingChecklist::class);
    $user = User::query()->where('email', 'ada@adeyemi.ng')->firstOrFail();
    app(OrganizationContext::class)->run($organization, function () use ($checklist, $user) {
        $checklist->complete(ChecklistItem::Collections, $user, ['bank_accounts' => 1]);
        $checklist->complete(ChecklistItem::Data, $user);
        // Completing twice keeps the first completion.
        $checklist->complete(ChecklistItem::Data);
    });

    $this->get('/o/adeyemi-realty')->assertSee('4 of 5 done')->assertSee('data-item="collections" data-status="done"', false);

    app(OrganizationContext::class)->run($organization, fn () => $checklist->complete(ChecklistItem::Branding, $user));

    $this->get('/o/adeyemi-realty')->assertOk()->assertDontSee('data-test="setup-checklist"', false);
    expect(app(OrganizationContext::class)->run($organization, fn () => $checklist->isOpen()))->toBeFalse();
});

it('shows the checklist only to members who set the organization up', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    signupStep($organization, 'saveTier', ['annualVolume' => '5,000,000', 'tier' => 1]);
    signupStep($organization, 'savePlan', ['plan' => 'starter']);
    signupStep($organization, 'setUpCollectionsLater');
    signupStep($organization, 'finish');

    $agent = joinAs(User::factory()->create(), $organization, OrganizationRole::Agent);
    $admin = joinAs(User::factory()->create(), $organization, OrganizationRole::Admin);

    $this->actingAs($agent)->get('/o/adeyemi-realty')->assertOk()->assertDontSee('data-test="setup-checklist"', false);
    $this->actingAs($admin)->get('/o/adeyemi-realty')->assertOk()->assertSee('data-test="setup-checklist"', false);
});

it('keeps a suspended organization\'s signup read-only', function () {
    signUpAndVerify();
    $organization = signupOrganization();
    $page = $this->get('/o/adeyemi-realty/setup')->assertOk();
    $organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

    LivewireHttp::update($this, LivewireHttp::snapshot($page), ['annualVolume' => '5,000,000', 'tier' => 1], [['saveTier']]);

    expect(onboardingOf($organization)->step)->toBe(OnboardingStep::Tier)
        ->and(onboardingOf($organization)->requested_tier)->toBeNull();
});
