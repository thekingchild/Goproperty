<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationKind;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\Notification;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverChanged;
use Modules\Properties\Models\Property;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-14: the handover pages (BILLING_PAYMENTS.md § 7): the offer form with
 * its building picker, rent collection and notice period, both lists, the
 * accept screen with the bill before and after, notice, the emailed link;
 * owners only; another organization's handovers answer 404; blocked while
 * GoProperty staff impersonate; P02-04's Billing transfers page redirects
 * here.
 */

beforeEach(fn () => handoverLightWorld());

afterEach(function () {
    freshRequest();
    handoversFreshRequest();
});

it('offers chosen buildings from the handovers page, with the picker, rent collection and notice period', function () {
    $archived = buildingIn($this->landlord, ['name' => 'Old Mill', 'slug' => 'old-mill', 'address' => '2 Mill Road', 'status' => 'archived']);

    $page = handoversPage($this->ngozi, 'eze-properties')->assertOk()
        ->assertSee('Hand buildings to an agency')
        ->assertSee("data-test=\"offer-building-{$this->palm->id}\"", false)
        ->assertSee("data-test=\"offer-building-{$this->lagoon->id}\"", false)
        ->assertDontSee("data-test=\"offer-building-{$archived->id}\"", false)
        ->assertSee('The agency collects rent')
        ->assertSee('Not available yet: it needs a collection account per building.')
        ->assertSee('data-nav="handovers"', false);

    // An agency not on GoProperty is not found, and nothing is sent.
    handoversFreshRequest();
    $refused = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::snapshot($page), ['target' => 'goproperty.ng/o/no-such-agency', 'buildings' => [(string) $this->palm->id]], [['offer']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('No agency on GoProperty matches that')
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(0);
    Notification::assertNothingSent();

    // A notice period out of range and the landlord collecting rent are refused.
    handoversFreshRequest();
    $refused = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::next($refused), ['target' => 'acme-homes', 'buildings' => [(string) $this->palm->id], 'noticeDays' => '200'], [['offer']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('noticeDays');
    handoversFreshRequest();
    $refused = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::next($refused), ['noticeDays' => '45', 'rentCollection' => 'owner'], [['offer']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('the agency collects rent')
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(0);

    handoversFreshRequest();
    $sent = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::next($refused), ['rentCollection' => 'agency', 'target' => 'ada.owner@example.com'], [['offer']])->assertOk();
    expect(LivewireHttp::html($sent))->toContain('Your buildings were offered to Adeyemi Realty Ltd')->toContain('Waiting for an answer');

    $handover = inOrganization($this->landlord, fn () => ManagementHandover::query()->with('buildings')->sole());
    expect($handover->to_organization_id)->toBe($this->agency->id)
        ->and($handover->notice_days)->toBe(45)
        ->and($handover->propertyIds())->toBe([$this->palm->id]);

    // The building is no longer offered in the picker while the offer is open.
    handoversPage($this->ngozi, 'eze-properties')->assertOk()
        ->assertDontSee("data-test=\"offer-building-{$this->palm->id}\"", false)
        ->assertSee("data-test=\"offer-building-{$this->lagoon->id}\"", false);

    // Withdraw it from the same page.
    handoversFreshRequest();
    $withdrawn = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::next($sent), [], [['withdraw', [$handover->id]]])->assertOk();
    expect(LivewireHttp::html($withdrawn))->toContain('Your offer to Adeyemi Realty Ltd was withdrawn.')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Cancelled);
});

it('shows an agency no offer form, only the handovers offered to it', function () {
    handoversPage($this->ada, 'acme-homes')->assertOk()
        ->assertSee('Landlords on GoProperty hand their buildings to you')
        ->assertDontSee('data-test="offer-button"', false)
        ->assertSee('No landlord has offered you buildings to manage.');

    $page = handoversPage($this->ada, 'acme-homes')->assertOk();
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($page), ['target' => 'eze-properties', 'buildings' => ['1']], [['offer']])->assertForbidden();
});

it('shows the offer in the receiving inbox, and the accept screen states the bill before and after, then accepts', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon], noticeDays: 60);

    handoversPage($this->ada, 'acme-homes')->assertOk()
        ->assertSee('Handovers offered to you')
        ->assertSee('Eze Properties')
        ->assertSee('2 buildings · 5 units')
        ->assertSee("data-test=\"review-{$handover->id}\"", false);

    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('Eze Properties offers you its buildings to manage')
        ->assertSee('Your GoProperty bill goes from ₦10,000/month to ₦12,800/month (before VAT) from your next billing day, 1 February 2027.', false)
        ->assertSee('The buildings add 5 units to the 9 your bill counts today.', false)
        ->assertSeeInOrder(['Lagoon View', '2 units', 'Palm Court', '3 units'])
        ->assertSee('The agency collects rent')
        ->assertSee('60 days')
        ->assertSee('data-test="accept-button"', false)
        ->assertDontSee('data-test="cheaper-plan"', false);

    handoversFreshRequest();
    $accepted = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($screen), [], [['accept']])->assertOk();
    expect(LivewireHttp::html($accepted))->toContain('You now manage Eze Properties&#039;s buildings')->toContain('Managed')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Active)
        ->and(storedHandover($handover)->monthly_after_kobo?->kobo)->toBe(1_280_000);

    // Both sides see where it stands; the agency's buildings list has them.
    handoversPage($this->ngozi, 'eze-properties')->assertOk()->assertSee('Managed');
    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('Adeyemi Realty Ltd has managed the buildings since')
        ->assertDontSee('data-test="accept-button"', false)
        ->assertSee('data-test="give-notice-button"', false);
    buildingsPage($this->ada, '', 'acme-homes')->assertOk()->assertSee('Palm Court')->assertSee('Lagoon View');
    buildingsPage($this->ngozi, '', 'eze-properties')->assertOk()->assertDontSee('Palm Court')->assertDontSee('Lagoon View');
});

it('declines on the accept screen, and refuses a bill that changed while the screen was open', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()->assertSee('to ₦11,400/month', false);

    // The agency adds an office meanwhile: 10 units of its own.
    unitIn($this->agency, inOrganization($this->agency, fn () => Property::query()->where('slug', 'office-block')->sole()), ['unit_label' => 'Office 10']);
    handoversFreshRequest();
    $changed = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($screen), [], [['accept']])->assertOk();
    expect(LivewireHttp::html($changed))->toContain('it would now be ₦12,100 a month')->toContain('to ₦12,100/month')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->landlord->id]);

    handoversFreshRequest();
    $declined = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::next($changed), [], [['decline']])->assertOk();
    expect(LivewireHttp::html($declined))->toContain('You declined the offer. Nothing moved.')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Declined);
    Notification::assertSentTo($this->ngozi, HandoverChanged::class, fn (HandoverChanged $n) => $n->event === 'handover.declined');
});

it('suggests a cheaper plan when the units after the handover make one cheaper', function () {
    $office = inOrganization($this->agency, fn () => Property::query()->where('slug', 'office-block')->sole());

    foreach (range(10, 45) as $n) {
        unitIn($this->agency, $office, ['unit_label' => "Office {$n}"]);
    }

    // 45 + 5 units: Starter would be ₦38,000; Growth is ₦35,000.
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('to ₦38,000/month', false)
        ->assertSee('The Growth plan would cost ₦35,000 a month for these units', false);
});

it('gives and withdraws notice from the handover page, either side, the giver only withdrawing', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 30);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()->assertSee('Resign: give notice');
    handoversFreshRequest();
    $given = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($screen), [], [['giveNotice']])->assertOk();
    expect(LivewireHttp::html($given))->toContain('Notice given.')->toContain('the buildings return to Eze Properties on 3 February 2027')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Ending);

    // The landlord sees the end date but cannot withdraw the agency's notice.
    $landlordScreen = handoversPage($this->ngozi, 'eze-properties', "/{$handover->id}")->assertOk()
        ->assertSee('Ending (notice given)')
        ->assertDontSee('data-test="withdraw-notice-button"', false);
    handoversFreshRequest();
    $refused = LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::snapshot($landlordScreen), [], [['withdrawNotice']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('Only the side that gave notice can withdraw it.')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Ending);

    handoversFreshRequest();
    $withdrawn = LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::next($given), [], [['withdrawNotice']])->assertOk();
    expect(LivewireHttp::html($withdrawn))->toContain('Notice withdrawn: management goes on.')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Active);
});

it('opens the emailed link in the receiving workspace, and nowhere else', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $token = handoverToken($this->ada);
    $stranger = joinAs(User::factory()->create(), Organization::factory()->create(['slug' => 'bello-homes']));

    handoversFreshRequest();
    $this->get("/handovers/{$token}")->assertRedirect('/login');

    foreach ([$stranger, $this->ngozi] as $user) {
        handoversFreshRequest();
        $this->actingAs($user)->get("/handovers/{$token}")->assertNotFound();
    }

    handoversFreshRequest();
    $this->actingAs($this->ada)->get('/handovers/'.str_repeat('x', 48))->assertNotFound();
    expect(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->opened_at))->toBeNull();

    handoversFreshRequest();
    $this->actingAs($this->ada)->get("/handovers/{$token}")->assertRedirect("/o/acme-homes/handovers/{$handover->id}");
    expect(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->opened_at))->not->toBeNull();

    // It is not a member invitation.
    handoversFreshRequest();
    $this->get("/invitations/{$token}")->assertNotFound();
    handoversFreshRequest();
    $this->actingAs($this->ada)->post("/invitations/{$token}")->assertNotFound();
    expect($this->ada->fresh()?->belongsToOrganization($this->landlord))->toBeFalse();
});

it('records the expiry when the link is opened after 14 days', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $token = handoverToken($this->ada);
    $this->travel(15)->days();

    handoversFreshRequest();
    $this->actingAs($this->ada)->get("/handovers/{$token}")->assertRedirect("/o/acme-homes/handovers/{$handover->id}");

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Expired);
    handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk()
        ->assertSee('The offer expired on')
        ->assertDontSee('data-test="accept-button"', false);
});

it('answers 404 for handovers of, or to, another organization', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $other = Organization::factory()->create(['slug' => 'bello-homes']);
    $dayo = joinAs(User::factory()->create(), $other);

    handoversPage($dayo, 'bello-homes', "/{$handover->id}")->assertNotFound();
    handoversPage($dayo, 'acme-homes', "/{$handover->id}")->assertNotFound();
    handoversPage($this->ada, 'eze-properties')->assertNotFound();
    handoversPage($this->ada, 'acme-homes', '/999999')->assertNotFound();

    // Withdrawing someone else's offer: not found in this organization.
    $page = handoversPage($this->ada, 'acme-homes')->assertOk();
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($page), [], [['withdraw', [$handover->id]]])->assertNotFound();
    $page = handoversPage($dayo, 'bello-homes')->assertOk();
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($dayo), LivewireHttp::snapshot($page), [], [['withdraw', [$handover->id]]])->assertNotFound();

    // An accept screen's snapshot cannot be replayed in another workspace.
    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk();
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($dayo), LivewireHttp::snapshot($screen), [], [['accept']])->assertNotFound();

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);
});

it('keeps the handover link out of Settings → Members', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $invitation = inOrganization($this->landlord, fn () => Invitation::query()->where('kind', InvitationKind::ManagementHandover)->sole());

    handoversFreshRequest();
    $members = $this->actingAs($this->ngozi)->get('/o/eze-properties/settings/members')->assertOk()->assertSee('No invitations are waiting.');
    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($this->ngozi), LivewireHttp::snapshot($members), [], [['revoke', [$invitation->id]]])->assertNotFound();

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);
});

it('shows the pages and the sidebar item to owners only', function (OrganizationRole $role) {
    $member = joinAs(User::factory()->create(), $this->agency, $role);
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);

    handoversPage($member, 'acme-homes')->assertForbidden();
    handoversPage($member, 'acme-homes', "/{$handover->id}")->assertForbidden();
    handoversFreshRequest();
    $this->actingAs($member)->get('/o/acme-homes')->assertDontSee('data-nav="handovers"', false);
    handoversFreshRequest();
    $this->actingAs($this->ada)->get('/o/acme-homes')->assertSee('data-nav="handovers"', false);
})->with([
    'administrator' => [OrganizationRole::Admin],
    'agent' => [OrganizationRole::Agent],
]);

it('blocks handovers while GoProperty staff impersonate, credit settlement included', function (ImpersonationMode $mode, string $side) {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $token = handoverToken($this->ada);
    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk();
    $index = handoversPage($this->ngozi, 'eze-properties')->assertOk();
    $landlordScreen = handoversPage($this->ngozi, 'eze-properties', "/{$handover->id}")->assertOk();
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);

    if ($side === 'agency') {
        enterSupport(startSupport($staff, $this->ada, $this->agency, $mode))->assertRedirect('/o/acme-homes');

        asSupport()->get('/o/acme-homes/handovers')->assertForbidden();
        asSupport()->get("/o/acme-homes/handovers/{$handover->id}")->assertForbidden();
        asSupport()->get("/handovers/{$token}")->assertForbidden();

        foreach (['accept', 'decline', 'giveNotice', 'withdrawNotice', ['confirmArrived', [1]]] as $call) {
            freshRequest();
            LivewireHttp::update(test(), LivewireHttp::snapshot($screen), [], [is_array($call) ? $call : [$call]])->assertForbidden();
        }
    } else {
        enterSupport(startSupport($staff, $this->ngozi, $this->landlord, $mode))->assertRedirect('/o/eze-properties');

        asSupport()->get('/o/eze-properties/handovers')->assertForbidden();
        freshRequest();
        LivewireHttp::update(test(), LivewireHttp::snapshot($index), ['target' => 'acme-homes', 'buildings' => [(string) $this->lagoon->id]], [['offer']])->assertForbidden();

        foreach ([['withdraw', [$handover->id]]] as $call) {
            freshRequest();
            LivewireHttp::update(test(), LivewireHttp::snapshot($index), [], [$call])->assertForbidden();
        }

        foreach (['withdraw', 'giveNotice', ['chooseSettlement', [1, 'refund']], ['recordRefund', [1]]] as $call) {
            freshRequest();
            LivewireHttp::update(test(), LivewireHttp::snapshot($landlordScreen), [], [is_array($call) ? $call : [$call]])->assertForbidden();
        }
    }

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(1)
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(3);
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
])->with(['agency', 'landlord']);

it('answers nothing in a suspended organization: no handovers page, no answer', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $screen = handoversPage($this->ada, 'acme-homes', "/{$handover->id}")->assertOk();
    $this->agency->forceFill(['status' => OrganizationStatus::Suspended])->save();

    handoversFreshRequest();
    LivewireHttp::update($this->actingAs($this->ada), LivewireHttp::snapshot($screen), [], [['accept']])->assertForbidden();
    handoversPage($this->ada, 'acme-homes')->assertForbidden();

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);
});

it('sends the old Billing transfers pages to the handovers page', function () {
    handoversFreshRequest();
    $this->actingAs($this->ada)->get('/o/acme-homes/billing-transfers')->assertStatus(301)->assertRedirect('/o/acme-homes/handovers');
    handoversFreshRequest();
    $this->actingAs($this->ada)->get('/o/acme-homes/billing-transfers/12')->assertStatus(301)->assertRedirect('/o/acme-homes/handovers');

    // Outsiders still get nothing; the old emailed links are gone.
    handoversFreshRequest();
    $this->actingAs($this->ngozi)->get('/o/acme-homes/billing-transfers')->assertNotFound();
    handoversFreshRequest();
    $this->actingAs($this->ada)->get('/billing-transfers/'.str_repeat('a', 48))->assertNotFound();
});
