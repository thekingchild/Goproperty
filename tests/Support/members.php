<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Tenancy\OrganizationContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the member and invitation tests (P01-04), required by the
 * files in tests/Feature/Members. Expect Notification::fake() and the
 * customer roles seeded.
 */

/** A fresh request, as production starts every one: no context, guard or Livewire state left over. */
function membersFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** Settings → Members as this user. */
function membersPage(User $user, string $slug = 'acme-homes'): TestResponse
{
    membersFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/settings/members");
}

/**
 * Loads Settings → Members and sends one Livewire update request to it,
 * through the full middleware stack (organization context, guards).
 *
 * @param  list<array{0: string, 1?: list<mixed>}>  $calls
 * @param  array<string, mixed>  $updates
 */
function membersCall(User $user, array $calls, array $updates = [], string $slug = 'acme-homes'): TestResponse
{
    $page = membersPage($user, $slug)->assertOk();
    membersFreshRequest();

    return LivewireHttp::update(test()->actingAs($user), LivewireHttp::snapshot($page), $updates, $calls);
}

/**
 * Invites through the members page; returns the token from the email.
 *
 * @param  list<string>  $roles
 */
function inviteMember(User $inviter, string $email, array $roles = ['agent'], string $slug = 'acme-homes'): string
{
    membersCall($inviter, [['invite']], ['email' => $email, 'roles' => $roles], $slug)->assertOk()->assertSee('Invitation sent to');

    return memberInvitationToken(Str::lower($email));
}

/** The token in the newest invitation email to this address. */
function memberInvitationToken(string $email): string
{
    $sent = Notification::sent(new AnonymousNotifiable, MemberInvitation::class, fn (MemberInvitation $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $email);
    expect($sent)->not->toBeEmpty();
    $last = $sent->last();
    assert($last instanceof MemberInvitation);

    return Str::afterLast($last->url(), '/');
}

/** Opens the invitation link as a guest (or the given user). */
function openInvitation(string $token, ?User $as = null): TestResponse
{
    membersFreshRequest();

    if ($as === null) {
        test()->flushSession();

        return test()->get("/invitations/{$token}");
    }

    return test()->actingAs($as)->get("/invitations/{$token}");
}

/**
 * Accepts as a new person (guest), the form on the invitation page.
 *
 * @param  array<string, string>  $fields
 */
function acceptAsNewPerson(string $token, array $fields = []): TestResponse
{
    membersFreshRequest();
    test()->flushSession();

    return test()->post("/invitations/{$token}", $fields + [
        'name' => 'Chidi Agent',
        'phone' => '0803 555 0101',
        'password' => 'a-long-password-1',
    ]);
}
