<?php

declare(strict_types=1);

use App\Models\User;
use App\Platform\Console\StepUp;
use PragmaRX\Google2FA\Google2FA;

/*
 * Security review 2026-11 (docs/security/review-2026-11.md § "Rate
 * limits"): the sensitive public and account endpoints answer 429 once
 * their limit is used, in one place, so a route that loses its limiter
 * fails here. The limits per organization, member and tenant on
 * invitation and sign-in-link sends (SMS and email cost money) and on
 * proof uploads, group posts and exports are proven by their modules'
 * tests (the register lists them).
 */

it('answers 429 once a public endpoint\'s limit is used', function (string $method, string $uri, array $data, int $limit) {
    foreach (range(1, $limit) as $try) {
        $status = $this->call($method, $uri, $data)->status();
        expect($status)->not->toBe(429, "try {$try} of {$limit}");
    }

    $this->call($method, $uri, $data)->assertTooManyRequests();
})->with([
    'sign-in (per identifier and address)' => ['POST', '/login', ['identifier' => 'ada@example.ng', 'password' => 'wrong-password'], 10],
    'two-factor code' => ['POST', '/two-factor-challenge', ['code' => '000000'], 5],
    'reset link request' => ['POST', '/forgot-password', ['identifier' => 'ada@example.ng'], 6],
    'reset form' => ['POST', '/reset-password', ['token' => 'x', 'email' => 'ada@example.ng', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'], 10],
    'signup' => ['POST', '/register', ['name' => 'Ada', 'email' => 'ada@example.ng', 'password' => 'x'], 5],
    'team invitation page' => ['GET', '/invitations/'.str_repeat('a', 40), [], 30],
    'team invitation accept' => ['POST', '/invitations/'.str_repeat('a', 40), [], 10],
    'tenant invitation accept' => ['POST', '/portal/invitations/'.str_repeat('a', 40), [], 10],
    'one-time sign-in link' => ['POST', '/portal/sign-in/'.str_repeat('a', 40), [], 10],
    'owner invitation accept' => ['POST', '/owner/invitations/'.str_repeat('a', 40), [], 10],
    'bill pay page' => ['GET', '/pay/GP-ABCDEFG', [], 30],
    'online payment start' => ['POST', '/pay/GP-ABCDEFG/online', [], 10],
    'document verification' => ['GET', '/verify/ABCD-EFGH-IJKL', [], 30],
    'support session hand-off' => ['GET', '/impersonation/enter/1', [], 20],
    'scheduler uptime link' => ['GET', '/up/scheduler/'.str_repeat('a', 40), [], 30],
]);

it('answers 429 once a signed-in account\'s limit is used', function (string $uri, array $data, int $limit) {
    $this->actingAs(User::factory()->create());

    foreach (range(1, $limit) as $try) {
        expect($this->post($uri, $data)->status())->not->toBe(429, "try {$try} of {$limit}");
    }

    $this->post($uri, $data)->assertTooManyRequests();
})->with([
    'confirm your password' => ['/user/confirm-password', ['password' => 'wrong-password'], 5],
    'verification email again' => ['/email/verification-notification', [], 6],
]);

it('stops the console\'s step-up after 5 wrong answers a minute, even with the right ones', function () {
    $staff = staffMember();
    $stepUp = app(StepUp::class);

    foreach (range(1, 5) as $try) {
        expect($stepUp->confirm($staff, 'wrong-password', '000000'))->toBe('The password or code is not right.');
    }

    expect($stepUp->confirm($staff, 'x-long-password-1', app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret)))
        ->toStartWith('Too many attempts.')
        ->and($stepUp->isFresh())->toBeFalse();
});
