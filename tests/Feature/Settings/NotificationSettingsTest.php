<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Preferences\NotificationPreferences;
use Livewire\Livewire;

/*
 * P05-02: per-user channel preferences (Settings → Notifications;
 * PLATFORM_CONSOLE.md § 5 "Categories": reminders follow per-user channel
 * preferences, security and transactional messages are always sent).
 */

it('opens for a signed-in person, every channel on until they choose, and saves their choice', function () {
    $user = User::factory()->create(['email' => 'kemi@example.com', 'phone' => '+2348031112222', 'phone_verified_at' => now()]);
    $preferences = app(NotificationPreferences::class);

    $this->actingAs($user)->get(route('notifications.edit'))->assertOk()
        ->assertSee('Reminders by email')
        ->assertSee('Reminders by SMS')
        ->assertSee('+2348031112222');

    Livewire::actingAs($user)->test('pages::settings.notifications')
        ->assertSet('remindersByEmail', true)
        ->assertSet('remindersBySms', true)
        ->set('remindersBySms', false)
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($preferences->allows($user, 'reminders', 'sms'))->toBeFalse()
        ->and($preferences->allows($user, 'reminders', 'email'))->toBeTrue()
        ->and($preferences->allows($user, 'reminders', 'in_app'))->toBeTrue()
        ->and($preferences->allows($user, 'security', 'email'))->toBeTrue()
        ->and($user->preferences)->toEqual(['reminders' => ['email' => true, 'sms' => false]]);

    Livewire::actingAs($user)->test('pages::settings.notifications')->assertSet('remindersBySms', false);
});

it('offers no SMS switch until the phone number is verified, and says how to get there', function () {
    $user = User::factory()->create(['phone' => '+2348031112222', 'phone_verified_at' => null]);

    $this->actingAs($user)->get(route('notifications.edit'))->assertOk()
        ->assertSee('data-test="sms-unverified"', false)
        ->assertDontSee('Reminders by SMS</', false);
});

it('sends guests to sign in', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});
