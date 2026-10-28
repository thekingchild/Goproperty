<?php

declare(strict_types=1);

use App\Enums\InvitationKind;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * P03-15: owner invitations never take over an account (the rules of the
 * tenant invitations, P03-01b, ONBOARDING_COMPLIANCE.md § 2). The link is
 * bound to the address or number it went to; an account that holds it
 * signs in first; another signed-in account is refused; a new account gets
 * only what the link proves; and one account follows one owner in an
 * organization. An invitation only ever reaches its own organization's
 * owner.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
    $this->bisi = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);
    buildingIn($this->acme, ['name' => 'Sunrise Court', 'slug' => 'sunrise-court', 'owner_profile_id' => $this->bisi->id]);
});

afterEach(fn () => ownerAccessFreshRequest());

describe('acceptance: account-takeover cases refused', function () {
    it('never opens an existing account from an emailed link: its holder signs in first, then accepts', function () {
        $holder = User::factory()->create(['name' => 'Bisi Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348059990000']);
        $token = inviteOwnerByEmail($this->owner, $this->bisi);

        openOwnerInvitation($token)->assertOk()->assertSee('Sign in to accept')->assertDontSee('Choose a password');
        expect(session('url.intended'))->toBe(route('owner.invitations.show', ['token' => $token]));

        // A password sent anyway makes no account and signs nobody in.
        acceptOwnerInvitation($token)->assertRedirect("/owner/invitations/{$token}");
        $this->assertGuest();
        expect(User::query()->where('email', 'bisi.adeyinka@example.com')->count())->toBe(1)
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull();

        // Signed in, the holder accepts with one button.
        openOwnerInvitation($token, $holder)->assertOk()->assertSee('Follow my buildings at Adeyemi Realty Ltd');
        acceptOwnerInvitation($token, $holder)->assertRedirect('/owner');
        expect(freshOwner($this->acme, $this->bisi)->user_id)->toBe($holder->id)
            ->and($holder->fresh()?->phone)->toBe('+2348059990000');
    });

    it('never opens the account that has the owner\'s phone number from a WhatsApp or shared link', function (string $channel) {
        $holder = User::factory()->create(['name' => 'Bisi Adeyinka', 'email' => null, 'email_verified_at' => null, 'phone' => '+2348052223333']);
        $token = $channel === 'whatsapp' ? inviteOwnerByWhatsApp($this->owner, $this->bisi) : inviteOwnerByLink($this->owner, $this->bisi);

        openOwnerInvitation($token)->assertOk()->assertSee('Sign in to accept');
        acceptOwnerInvitation($token)->assertRedirect("/owner/invitations/{$token}");
        $this->assertGuest();
        expect(User::query()->where('phone', '+2348052223333')->count())->toBe(1)
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull();

        acceptOwnerInvitation($token, $holder)->assertRedirect('/owner');
        expect(freshOwner($this->acme, $this->bisi)->user_id)->toBe($holder->id);
    })->with(['whatsapp', 'link']);

    it('refuses a signed-in account the link is not for, and changes nothing', function (string $channel) {
        $stranger = User::factory()->create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'phone' => '+2348050000001']);
        $token = match ($channel) {
            'email' => inviteOwnerByEmail($this->owner, $this->bisi),
            'whatsapp' => inviteOwnerByWhatsApp($this->owner, $this->bisi),
            default => inviteOwnerByLink($this->owner, $this->bisi),
        };

        openOwnerInvitation($token, $stranger)->assertForbidden()->assertSee('This link is for another account');
        acceptOwnerInvitation($token, $stranger)->assertForbidden();

        expect(freshOwner($this->acme, $this->bisi)->user_id)->toBeNull()
            ->and($stranger->roleNamesIn($this->acme))->toBe([])
            ->and($stranger->fresh()?->email)->toBe('stranger@example.com')
            ->and($stranger->fresh()?->phone)->toBe('+2348050000001');
        openOwnerInvitation($token)->assertOk();
    })->with(['email', 'whatsapp', 'link']);

    it('lets a signed-in account without that address or number join, and gives it what the link proves', function () {
        $phoneOnly = User::factory()->create(['name' => 'Bisi Adeyinka', 'email' => null, 'email_verified_at' => null, 'phone' => '+2348059990000']);
        $token = inviteOwnerByEmail($this->owner, $this->bisi);

        openOwnerInvitation($token, $phoneOnly)->assertOk()->assertSee('Follow my buildings');
        acceptOwnerInvitation($token, $phoneOnly)->assertRedirect('/owner');

        $fresh = $phoneOnly->fresh();
        expect($fresh?->email)->toBe('bisi.adeyinka@example.com')
            ->and($fresh?->email_verified_at)->not->toBeNull()
            ->and($fresh?->phone)->toBe('+2348059990000')
            ->and(freshOwner($this->acme, $this->bisi)->user_id)->toBe($phoneOnly->id);
    });

    it('gives a new account only what the link proves: never a number someone else holds, never an address from a shared link', function () {
        User::factory()->create(['name' => 'Someone Else', 'email' => 'someone@example.com', 'phone' => '+2348052223333']);
        acceptOwnerInvitation(inviteOwnerByEmail($this->owner, $this->bisi))->assertRedirect('/owner/welcome');

        $byEmail = User::query()->where('email', 'bisi.adeyinka@example.com')->sole();
        expect($byEmail->phone)->toBeNull();

        $company = ownerIn($this->acme, ['type' => 'company', 'company_name' => 'Ikoyi Holdings Ltd', 'email' => 'info@ikoyiholdings.ng', 'phone' => '+2348054445555']);
        acceptOwnerInvitation(inviteOwnerByLink($this->owner, $company), null, ['name' => 'Ikoyi Holdings', 'password' => 'a-long-password-1'])->assertRedirect('/owner/welcome');

        $byLink = User::query()->where('phone', '+2348054445555')->sole();
        expect($byLink->email)->toBeNull()->and($byLink->phone_verified_at)->toBeNull()
            ->and(User::query()->where('email', 'info@ikoyiholdings.ng')->exists())->toBeFalse();
    });

    it('refuses an account that already follows another owner\'s buildings in the same organization', function () {
        $ngozi = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        linkOwner($this->acme, ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com']), $ngozi);
        $second = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze (2)', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
        $token = inviteOwnerByEmail($this->owner, $second);

        openOwnerInvitation($token, $ngozi)->assertForbidden()->assertSee('already follows another owner');
        acceptOwnerInvitation($token, $ngozi)->assertForbidden()->assertSee('already follows another owner');

        expect(freshOwner($this->acme, $second)->user_id)->toBeNull();
    });
});

describe('cross-org', function () {
    it('never lets an invitation of one organization touch another organization\'s owner', function () {
        $foreign = ownerIn($this->other, ['type' => 'person', 'first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);

        // A forged Acme row pointing at Bello's owner is never found in Acme's context.
        $token = inviteOwnerByLink($this->owner, $this->bisi);
        inOrganization($this->acme, fn () => Invitation::query()->where('kind', InvitationKind::Owner)->update(['owner_profile_id' => $foreign->id]));
        openOwnerInvitation($token)->assertNotFound();
        acceptOwnerInvitation($token)->assertNotFound();

        expect(freshOwner($this->other, $foreign)->user_id)->toBeNull()
            ->and(User::query()->where('phone', '+2348052223333')->exists())->toBeFalse();

        // Accepting Acme's real link links Acme's owner only, though Bello has the same person.
        inOrganization($this->acme, fn () => Invitation::query()->where('kind', InvitationKind::Owner)->update(['owner_profile_id' => $this->bisi->id]));
        acceptOwnerInvitation($token)->assertRedirect('/owner/welcome');
        $user = User::query()->where('phone', '+2348052223333')->sole();
        expect(freshOwner($this->acme, $this->bisi)->user_id)->toBe($user->id)
            ->and(freshOwner($this->other, $foreign)->user_id)->toBeNull()
            ->and($user->roleNamesIn($this->other))->toBe([])
            ->and($user->roleNamesIn($this->acme))->toBe([OrganizationRole::PropertyOwner->value]);
    });
});
