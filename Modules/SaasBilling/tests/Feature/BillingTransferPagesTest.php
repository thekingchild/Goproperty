<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationKind;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Modules\SaasBilling\Enums\BillingTransferStatus;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Notifications\BillingTransferAnswered;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/*
 * P02-04: the billing-transfer pages (BILLING_PAYMENTS.md § 7): the offer
 * form, the invite inbox, the accept screen with the amount, the emailed
 * link; owners only; another organization's offers answer 404; blocked
 * while GoProperty staff impersonate and read-only while suspended.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes', 'type' => OrganizationType::PropertyOwner, 'signup_plan_key' => 'starter']);
    $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    billableUnits($this->acme, 30);

    $this->agency = Organization::factory()->create(['slug' => 'bright-agency', 'name' => 'Bright Agency']);
    $this->bola = joinAs(User::factory()->create(['name' => 'Bola Owner', 'email' => 'bola@bright.ng']), $this->agency);
});

afterEach(function () {
    freshRequest();
    app(OrganizationContext::class)->clear();
});

it('offers the bill from the billing transfers page', function () {
    $page = transfersPage($this->ada, 'acme-homes')->assertOk()
        ->assertSee('Your bill today: ₦24,000 a month before 7.5% VAT (Starter plan, 30 units).', false)
        ->assertSee('data-nav="billing-transfers"', false);

    // An organization not on GoProperty is not found, and nothing is sent.
    transfersFreshRequest();
    $refused = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($page), ['target' => 'goproperty.ng/o/no-such-agency'], [['offer']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('No organization on GoProperty matches that')
        ->and(inOrganization($this->acme, fn () => BillingTransferRequest::query()->count()))->toBe(0);
    Notification::assertNothingSent();

    transfersFreshRequest();
    $sent = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::next($refused), ['target' => 'bola@bright.ng'], [['offer']])->assertOk();
    expect(LivewireHttp::html($sent))->toContain('Your bill was offered to Bright Agency')->toContain('Waiting for an answer');

    $offer = inOrganization($this->acme, fn () => BillingTransferRequest::query()->sole());
    expect($offer->to_organization_id)->toBe($this->agency->id)
        ->and($offer->initiated_by_user_id)->toBe($this->ada->id);

    // Withdraw it from the same page.
    transfersFreshRequest();
    $withdrawn = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::next($sent), [], [['withdraw', [$offer->id]]])->assertOk();
    expect(LivewireHttp::html($withdrawn))->toContain('Your offer to Bright Agency was withdrawn.')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Cancelled);
});

it('shows the offer in the receiving inbox and accepts it on the accept screen, which states the amount', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $today = now()->timezone('Africa/Lagos')->format('j F Y');

    transfersPage($this->bola, 'bright-agency')->assertOk()
        ->assertSee('Offers to you')
        ->assertSee('Acme Homes')
        ->assertSee("data-test=\"review-{$offer->id}\"", false);

    $screen = transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk()
        ->assertSee("You are taking over the GoProperty subscription bill for ₦24,000/month from {$today}.", false)
        ->assertSee('Starter plan with 30 units', false)
        ->assertSee('data-test="accept-button"', false);

    transfersFreshRequest();
    $accepted = LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::snapshot($screen), [], [['accept']])->assertOk();
    expect(LivewireHttp::redirect($accepted))->toBe('http://localhost/o/bright-agency/billing-transfers')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Completed)
        ->and(storedTransfer($offer)->monthly_amount_kobo?->kobo)->toBe(2_400_000);

    transfersPage($this->bola, 'bright-agency')->assertOk()->assertSee('You took over Acme Homes&#039;s GoProperty bill.', false);
    transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk()
        ->assertSee('You accepted this offer on')
        ->assertDontSee('data-test="accept-button"', false);
    // The offering side sees the answer.
    transfersPage($this->ada, 'acme-homes')->assertOk()->assertSee('Accepted')->assertSee('₦24,000 a month', false);
});

it('declines on the accept screen, and refuses an amount that changed while the screen was open', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $screen = transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk();

    billableUnits($this->acme, 2);
    transfersFreshRequest();
    $changed = LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::snapshot($screen), [], [['accept']])->assertOk();
    expect(LivewireHttp::html($changed))->toContain('it is now ₦25,400 a month')->toContain('for ₦25,400/month')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);

    transfersFreshRequest();
    $declined = LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::next($changed), [], [['decline']])->assertOk();
    expect(LivewireHttp::redirect($declined))->toBe('http://localhost/o/bright-agency/billing-transfers')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Declined);
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 1);
});

it('opens the emailed link in the receiving workspace, and nowhere else', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $token = offerToken($this->bola);
    $stranger = joinAs(User::factory()->create(), Organization::factory()->create(['slug' => 'other-realty']));

    // Signed out: sign in first, then back here.
    transfersFreshRequest();
    $this->get("/billing-transfers/{$token}")->assertRedirect('/login');

    // Nobody but the receiving organization's members gets anywhere, and nothing is recorded for them.
    transfersFreshRequest();
    $this->actingAs($stranger)->get("/billing-transfers/{$token}")->assertNotFound();
    transfersFreshRequest();
    $this->actingAs($this->ada)->get("/billing-transfers/{$token}")->assertNotFound();
    transfersFreshRequest();
    $this->actingAs($this->bola)->get('/billing-transfers/'.str_repeat('x', 48))->assertNotFound();
    expect(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->opened_at))->toBeNull();

    transfersFreshRequest();
    $this->actingAs($this->bola)->get("/billing-transfers/{$token}")->assertRedirect("/o/bright-agency/billing-transfers/{$offer->id}");
    expect(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->opened_at))->not->toBeNull();

    // It is not a member invitation: the members' link page does not know it.
    transfersFreshRequest();
    $this->get("/invitations/{$token}")->assertNotFound();
    transfersFreshRequest();
    $this->actingAs($this->bola)->post("/invitations/{$token}")->assertNotFound();
    expect($this->bola->fresh()?->belongsToOrganization($this->acme))->toBeFalse();
});

it('records the expiry when the link is opened after 14 days', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $token = offerToken($this->bola);
    $this->travel(15)->days();

    transfersFreshRequest();
    $this->actingAs($this->bola)->get("/billing-transfers/{$token}")->assertRedirect("/o/bright-agency/billing-transfers/{$offer->id}");

    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Expired);
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 1);
    transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk()
        ->assertSee('This offer expired on')
        ->assertDontSee('data-test="accept-button"', false);
});

it('answers 404 for offers of, or to, another organization', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $dayo = joinAs(User::factory()->create(), $other);

    // Another organization's owner, in their own workspace or in the agency's.
    transfersPage($dayo, 'other-realty', "/{$offer->id}")->assertNotFound();
    transfersPage($dayo, 'bright-agency', "/{$offer->id}")->assertNotFound();
    // The offering organization has no accept screen for its own offer.
    transfersPage($this->ada, 'acme-homes', "/{$offer->id}")->assertNotFound();
    // The receiving owner cannot open the offering organization's workspace.
    transfersPage($this->bola, 'acme-homes')->assertNotFound();

    // Withdrawing someone else's offer: not found in this organization.
    $page = transfersPage($this->bola, 'bright-agency')->assertOk();
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::snapshot($page), [], [['withdraw', [$offer->id]]])->assertNotFound();
    $page = transfersPage($dayo, 'other-realty')->assertOk();
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($dayo), LivewireHttp::snapshot($page), [], [['withdraw', [$offer->id]]])->assertNotFound();

    // An accept screen's snapshot cannot be replayed in another workspace.
    $screen = transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk();
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($dayo), LivewireHttp::snapshot($screen), [], [['accept']])->assertNotFound();

    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);
});

it('keeps the transfer link out of Settings → Members', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $invitation = inOrganization($this->acme, fn () => Invitation::query()->where('kind', InvitationKind::OrgBillingTransfer)->sole());

    $members = membersPageFor($this->ada)->assertOk()->assertSee('No invitations are waiting.');
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($members), [], [['revoke', [$invitation->id]]])->assertNotFound();
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($members), [], [['resend', [$invitation->id]]])->assertNotFound();

    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);
});

it('shows the pages and the sidebar item to owners only', function (OrganizationRole $role) {
    $member = joinAs(User::factory()->create(), $this->agency, $role);
    $offer = offerBill($this->ada, $this->acme, $this->agency);

    transfersPage($member, 'bright-agency')->assertForbidden();
    transfersPage($member, 'bright-agency', "/{$offer->id}")->assertForbidden();
    transfersFreshRequest();
    $this->actingAs($member)->get('/o/bright-agency')->assertDontSee('data-nav="billing-transfers"', false);
    transfersFreshRequest();
    $this->actingAs($member)->get('/billing-transfers/'.offerToken($this->bola))->assertRedirect("/o/bright-agency/billing-transfers/{$offer->id}");
    transfersPage($member, 'bright-agency', "/{$offer->id}")->assertForbidden();
})->with([
    'administrator' => [OrganizationRole::Admin],
    'agent' => [OrganizationRole::Agent],
]);

it('blocks billing transfers while GoProperty staff impersonate', function (ImpersonationMode $mode) {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $token = offerToken($this->bola);
    $screen = transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk();
    $index = transfersPage($this->bola, 'bright-agency')->assertOk();
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);

    enterSupport(startSupport($staff, $this->bola, $this->agency, $mode))->assertRedirect('/o/bright-agency');

    asSupport()->get('/o/bright-agency/billing-transfers')->assertForbidden();
    asSupport()->get("/o/bright-agency/billing-transfers/{$offer->id}")->assertForbidden();
    asSupport()->get("/billing-transfers/{$token}")->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($screen), [], [['accept']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($screen), [], [['decline']])->assertForbidden();
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::snapshot($index), ['target' => 'acme-homes'], [['offer']])->assertForbidden();

    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending)
        ->and(inOrganization($this->agency, fn () => BillingTransferRequest::query()->count()))->toBe(0)
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(3);
    Notification::assertNotSentTo($this->ada, BillingTransferAnswered::class);
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
]);

it('keeps billing transfers read-only while the organization is suspended', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $screen = transfersPage($this->bola, 'bright-agency', "/{$offer->id}")->assertOk();
    $index = transfersPage($this->bola, 'bright-agency')->assertOk();
    $this->agency->forceFill(['status' => OrganizationStatus::Suspended])->save();

    // Owners still see the offers…
    transfersPage($this->bola, 'bright-agency')->assertOk()->assertSee('Acme Homes');
    // …but neither answer nor offer.
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::snapshot($screen), [], [['accept']])->assertForbidden();
    transfersFreshRequest();
    LivewireHttp::update($this->actingAs($this->bola), LivewireHttp::snapshot($index), ['target' => 'acme-homes'], [['offer']])->assertForbidden();

    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending)
        ->and(inOrganization($this->agency, fn () => BillingTransferRequest::query()->count()))->toBe(0);
});

/** Settings → Members as this user (the transfer link must not show there). */
function membersPageFor(User $user): TestResponse
{
    transfersFreshRequest();

    return test()->actingAs($user)->get('/o/acme-homes/settings/members');
}
