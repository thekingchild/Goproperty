<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;

/*
 * P01-01 acceptance (IMPLEMENTATION_PLAN.md § 3): two organizations; a user
 * in both can switch between them; the last choice is where they land next
 * time; /o/{slug} of an organization you do not belong to (or belong to only
 * as a tenant or property owner) is a 404, like an unknown slug
 * (ONBOARDING_COMPLIANCE.md § 1, ARCHITECTURE.md § 4–5).
 */

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Acme Homes', 'slug' => 'acme-homes', 'previous_slugs' => ['acme-old']]);
    $this->other = Organization::factory()->create(['name' => 'Other Realty', 'slug' => 'other-realty', 'previous_slugs' => ['other-old']]);
    $this->user = User::factory()->create(['email' => 'tola@adeyemirealty.ng']);
});

function signInAs(string $email = 'tola@adeyemirealty.ng'): TestResponse
{
    return test()->post('/login', ['identifier' => $email, 'password' => 'password']);
}

/** @return list<int> */
function defaultOrganizations(User $user): array
{
    return DB::table('organization_user')->where('user_id', $user->id)->where('is_default', true)->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
}

it('lets a member of two organizations switch between them', function () {
    joinAs($this->user, $this->acme, OrganizationRole::Owner);
    joinAs($this->user, $this->other, OrganizationRole::Agent);

    // First sign-in: nothing chosen yet, so the organization picker.
    signInAs()->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk()
        ->assertSee('data-test="organization-picker"', false)
        ->assertSee('Acme Homes')->assertSee('Other Realty')
        ->assertSee(route('organizations.switch', 'acme-homes'), false)
        ->assertSee(route('organizations.switch', 'other-realty'), false);

    $this->post(route('organizations.switch', 'other-realty'))->assertRedirect('/o/other-realty');
    expect(defaultOrganizations($this->user))->toBe([$this->other->id]);

    $this->get('/o/other-realty')->assertOk()
        ->assertSee('Other Realty')
        ->assertSeeInOrder(['data-test="org-switcher"', 'Other Realty', 'data-test="current-role"', 'Agent'], false)
        ->assertSee(route('organizations.switch', 'acme-homes'), false);

    $this->post(route('organizations.switch', 'acme-homes'))->assertRedirect('/o/acme-homes');
    expect(defaultOrganizations($this->user))->toBe([$this->acme->id]);

    $this->get('/o/acme-homes')->assertOk()
        ->assertSeeInOrder(['data-test="org-switcher"', 'Acme Homes', 'data-test="current-role"', 'Owner'], false);

    // The last choice is remembered for the next sign-in.
    $this->post('/logout');
    signInAs()->assertRedirect('/o/acme-homes');
});

it('takes a member of one organization straight to it', function () {
    joinAs($this->user, $this->acme, OrganizationRole::Agent);

    signInAs()->assertRedirect('/o/acme-homes');
    $this->get('/o/acme-homes')->assertOk()->assertSee('Acme Homes');
});

it('lands on the workspace after a 2FA challenge and a passkey too', function () {
    joinAs($this->user, $this->acme);
    $this->user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    signInAs();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'abcde-12345'])->assertRedirect('/o/acme-homes');

    $request = Request::create('/passkeys/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $this->user);

    expect(app(PasskeyLoginResponse::class)->toResponse($request)->getContent())
        ->toBe(json_encode(['redirect' => 'http://localhost/o/acme-homes']));
});

it('still sends a user to the page they asked for', function () {
    joinAs($this->user, $this->acme);
    joinAs($this->user, $this->other, OrganizationRole::Agent);

    $this->get('/o/other-realty')->assertRedirect('/login');
    signInAs()->assertRedirect('/o/other-realty');
});

it('answers 404 for an organization the user does not belong to', function (string $slug) {
    joinAs($this->user, $this->acme);
    Organization::factory()->create(['slug' => 'third-estates']);

    $this->actingAs($this->user)->get("/o/{$slug}")->assertNotFound();
    $this->actingAs($this->user)->post(route('organizations.switch', $slug))->assertNotFound();
    expect(defaultOrganizations($this->user))->toBe([]);
})->with([
    'another organization' => 'other-realty',
    'its previous slug' => 'other-old',
    'a third organization' => 'third-estates',
    'an unknown slug' => 'no-such-org',
]);

it('keeps a member\'s old slug working as a redirect', function () {
    joinAs($this->user, $this->acme);

    $this->actingAs($this->user)->get('/o/acme-old')->assertStatus(301)->assertRedirect('http://localhost/o/acme-homes');
});

it('keeps tenants and property owners out of the workspace', function (OrganizationRole $role) {
    joinAs($this->user, $this->acme);
    joinAs($this->user, $this->other, $role);

    $this->actingAs($this->user)->get('/o/other-realty')->assertNotFound();
    $this->actingAs($this->user)->get('/o/other-old')->assertNotFound();
    $this->actingAs($this->user)->post(route('organizations.switch', 'other-realty'))->assertNotFound();

    // Never offered in the switcher, the sidebar or the picker.
    $this->actingAs($this->user)->get('/o/acme-homes')->assertOk()
        ->assertDontSee('Other Realty')
        ->assertDontSee('/o/other-realty', false);
    $this->actingAs($this->user)->get('/dashboard')->assertOk()->assertDontSee('Other Realty');

    // One workspace left, so signing in goes straight to it.
    $this->post('/logout');
    signInAs()->assertRedirect('/o/acme-homes');
})->with([OrganizationRole::Tenant, OrganizationRole::PropertyOwner]);

it('sends a user who is only a tenant to the start page, not a workspace', function () {
    joinAs($this->user, $this->acme, OrganizationRole::Tenant);

    signInAs()->assertRedirect('/dashboard');
    $this->get('/o/acme-homes')->assertNotFound();
    $this->get('/dashboard')->assertOk()->assertSee('Pick where to start')->assertDontSee('Acme Homes');
});

it('lets a tenant who is also an agent of the same organization in', function () {
    joinAs($this->user, $this->acme, OrganizationRole::Tenant);
    joinAs($this->user, $this->acme, OrganizationRole::Agent);

    $this->actingAs($this->user)->get('/o/acme-homes')->assertOk();
});

it('does not admit a member without a workspace role', function () {
    $this->user->organizations()->attach($this->acme);

    $this->actingAs($this->user)->get('/o/acme-homes')->assertNotFound();
});

it('keeps exactly one default organization', function () {
    joinAs($this->user, $this->acme);
    joinAs($this->user, $this->other, OrganizationRole::Admin);

    foreach (['acme-homes', 'other-realty', 'other-realty', 'acme-homes'] as $slug) {
        $this->actingAs($this->user)->post(route('organizations.switch', $slug))->assertRedirect("/o/{$slug}");
    }

    expect(defaultOrganizations($this->user))->toBe([$this->acme->id]);
});

it('requires sign-in to switch', function () {
    $this->post(route('organizations.switch', 'acme-homes'))->assertRedirect('/login');
    expect(route('organizations.switch', 'acme-homes'))->toBe('http://localhost/organizations/acme-homes/switch');
});
