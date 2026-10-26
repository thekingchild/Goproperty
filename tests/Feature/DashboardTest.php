<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(joinAs(User::factory()->create(), Organization::factory()->create()));

    $this->get(route('dashboard'))->assertOk();
});

// Belonging to no organization at all means signup is not finished
// (ONBOARDING_COMPLIANCE.md § 2): the organization step comes next.
test('users without any organization continue signup', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('onboarding.organization'));
});
