<?php

declare(strict_types=1);

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('password.confirm'))->assertOk();
});

test('the password can be confirmed (security review SR-11)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->toBeInt();
});

test('the password is not confirmed with a wrong one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});
