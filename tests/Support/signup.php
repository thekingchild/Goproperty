<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\LivewireHttp;

/*
 * Signup wizard steps over HTTP, for tests/Feature/Onboarding
 * (ONBOARDING_COMPLIANCE.md § 2). Expects Notification::fake() and the
 * customer roles seeded.
 */

/** Account step and the verification link opened in the same browser; returns the signed-in user. */
function signUpAndVerify(string $email = 'ada@adeyemi.ng'): User
{
    test()->post('/register', [
        'name' => 'Ada Adeyemi',
        'email' => $email,
        'phone' => '0803 123 4567',
        'password' => 'a-long-password-1',
    ])->assertRedirect(route('register.sent'));

    $user = User::query()->where('email', $email)->firstOrFail();
    $link = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$link): bool {
        $link = $notification->toMail($user)->actionUrl;

        return true;
    });

    test()->get($link)->assertRedirect('/dashboard?verified=1');
    test()->assertAuthenticatedAs($user);

    return $user->fresh();
}

/**
 * Organization step through Livewire; returns the new organization.
 *
 * @param  array<string, mixed>  $fields
 */
function signupOrganization(array $fields = []): Organization
{
    $page = test()->get('/signup/organization')->assertOk()->assertSee('Tell us about your organization');

    $response = LivewireHttp::update(test(), LivewireHttp::snapshot($page), [
        'form.type' => $fields['type'] ?? 'agency',
        'form.name' => $fields['name'] ?? 'Adeyemi Realty',
        'form.state' => $fields['state'] ?? 'Lagos',
        'form.lga' => 'Ikeja',
        'slug' => $fields['slug'] ?? 'adeyemi-realty',
    ], [['save']])->assertOk();

    $organization = Organization::query()->where('slug', $fields['slug'] ?? 'adeyemi-realty')->firstOrFail();
    expect(LivewireHttp::redirect($response))->toBe(route('workspace.setup', $organization));

    return $organization;
}

/**
 * One wizard step through Livewire on /o/{slug}/setup.
 *
 * @param  array<string, mixed>  $updates
 */
function signupStep(Organization $organization, string $method, array $updates = []): TestResponse
{
    $page = test()->get("/o/{$organization->slug}/setup")->assertOk();

    return LivewireHttp::update(test(), LivewireHttp::snapshot($page), $updates, [[$method]])->assertOk();
}

function onboardingOf(Organization $organization): OrganizationOnboarding
{
    return app(OrganizationContext::class)->run($organization, fn () => OrganizationOnboarding::query()->firstOrFail());
}

function signInAgain(string $email = 'ada@adeyemi.ng'): TestResponse
{
    test()->post('/logout');

    return test()->post('/login', ['identifier' => $email, 'password' => 'a-long-password-1']);
}

/** @return array<string, mixed> the component's public data in a snapshot */
function snapshotData(string $snapshot): array
{
    $data = json_decode($snapshot, true)['data'] ?? [];

    return is_array($data) ? $data : [];
}
