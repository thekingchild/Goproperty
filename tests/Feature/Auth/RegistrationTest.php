<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $this->get(route('register'))->assertOk();
});

// Signup (ONBOARDING_COMPLIANCE.md § 2): the account is created unverified
// and nobody is signed in until the emailed link is opened.
test('new users can register', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'phone' => '0803 123 4567',
        'password' => 'long-password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('register.sent', absolute: false));

    $this->assertGuest();
    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->phone)->toBe('+2348031234567');
    Notification::assertSentTo($user, VerifyEmail::class);
});
