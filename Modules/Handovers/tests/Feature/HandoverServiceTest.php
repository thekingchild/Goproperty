<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Enums\RentCollection;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Handovers\HandoverTargets;
use Modules\Handovers\Handovers\IncomingHandovers;
use Modules\Handovers\Handovers\OfferableBuildings;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverChanged;
use Modules\Handovers\Notifications\HandoverOffered;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Models\Property;

require_once __DIR__.'/helpers.php';

/*
 * P03-14: the management handover's state machine (BILLING_PAYMENTS.md
 * § 7.2), changed only through HandoverService: offer, accept, decline,
 * withdraw, expire after 14 days, give and withdraw notice, end; invalid
 * transitions refused with nothing changed; only org-owners act; both
 * sides told once per transition; the building picker's rules (§ 7.1).
 */

beforeEach(fn () => handoverLightWorld());

afterEach(fn () => handoversFreshRequest());

it('records an offer, an acceptance and a decline through HandoverService', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon], noticeDays: 45);

    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Pending)
        ->and($stored->organization_id)->toBe($this->landlord->id)
        ->and($stored->to_organization_id)->toBe($this->agency->id)
        ->and($stored->landlord_organization_id)->toBe($this->landlord->id)
        ->and($stored->initiated_by_user_id)->toBe($this->ngozi->id)
        ->and($stored->rent_collected_by)->toBe(RentCollection::Agency)
        ->and($stored->notice_days)->toBe(45)
        ->and($stored->expires_at?->toIso8601String())->toBe(now()->addDays(14)->toIso8601String())
        ->and($stored->buildings->map(fn ($b) => [$b->property_id, $b->property_name, $b->unit_count])->all())->toBe([[$this->lagoon->id, 'Lagoon View', 2], [$this->palm->id, 'Palm Court', 3]]);

    // The link is a Core invitation of the handover kind, owned by the offering organization.
    $invitation = inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole());
    expect($invitation->kind)->toBe(InvitationKind::ManagementHandover)
        ->and($invitation->target_organization_id)->toBe($this->agency->id)
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->email)->toBeNull()
        ->and($invitation->roles)->toBe([])
        ->and($invitation->token_hash)->toBe(hash('sha256', handoverToken($this->ada)))
        ->and($invitation->expires_at?->equalTo($stored->expires_at))->toBeTrue();

    // The receiving organization finds it; the offering one does not see it as incoming.
    expect(incomingHandover($this->agency, $handover)?->id)->toBe($handover->id)
        ->and(incomingHandover($this->landlord, $handover))->toBeNull();

    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));
    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Active)
        ->and($stored->responded_by_user_id)->toBe($this->ada->id)
        ->and($stored->accepted_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($stored->billable_units)->toBe(5)
        ->and($stored->monthly_before_kobo?->kobo)->toBe(1_000_000)
        ->and($stored->monthly_after_kobo?->kobo)->toBe(1_280_000);
    $invitation = inOrganization($this->landlord, fn () => Invitation::query()->findOrFail($invitation->id));
    expect($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->accepted_by_user_id)->toBe($this->ada->id);

    // A second offer, to another agency, declined.
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes', 'type' => OrganizationType::Hybrid]);
    $dayo = joinAs(User::factory()->create(['name' => 'Dayo Bello', 'email' => 'dayo.bello@example.com']), $other);
    $third = buildingIn($this->landlord, ['name' => 'Cedar House', 'slug' => 'cedar-house', 'address' => '9 Bourdillon Road, Ikoyi']);
    $second = offerHandover($this->ngozi, $this->landlord, $other, [$third]);
    declineHandover($dayo, $other, incomingHandover($other, $second));
    expect(storedHandover($second)->status)->toBe(HandoverStatus::Declined)
        ->and(storedHandover($second)->responded_by_user_id)->toBe($dayo->id)
        ->and(storedHandover($second)->monthly_after_kobo)->toBeNull()
        ->and(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $second->id)->sole()->status))->toBe(InvitationStatus::Declined)
        ->and(organizationsOf('properties', [$third->id]))->toBe([$third->id => $this->landlord->id]);

    // Each step is in the audit log, the handover as subject, the actor as causer.
    $entries = AuditEntry::query()->where('log_name', HandoverService::LOG)->where('subject_type', (new ManagementHandover)->getMorphClass())->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['handover.offered', 'handover.accepted', 'handover.offered', 'handover.declined'])
        ->and($entries->pluck('causer_id')->all())->toBe([$this->ngozi->id, $this->ada->id, $this->ngozi->id, $dayo->id])
        ->and($entries[1]->subject_id)->toBe($handover->id)
        ->and($entries[1]->properties['monthly_after_kobo'])->toBe(1_280_000)
        ->and($entries[1]->properties['to_organization_id'])->toBe($this->agency->id);

    // Both organizations have a handover entry for the move (each as subject).
    $moved = AuditEntry::query()->where('log_name', HandoverService::LOG)->where('subject_type', (new Organization)->getMorphClass())->orderBy('id')->get();
    expect($moved->map(fn ($entry) => [$entry->event, $entry->subject_id])->all())->toBe([['handover.records_moved_out', $this->landlord->id], ['handover.records_moved_in', $this->agency->id]]);
});

it('records a withdrawal by the offering organization, after which the buildings can be offered again', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    withdrawHandover($this->emeka, $this->landlord, $handover);

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Cancelled)
        ->and(storedHandover($handover)->cancelled_at)->not->toBeNull()
        ->and(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->status))->toBe(InvitationStatus::Cancelled)
        ->and(AuditEntry::query()->where('event', 'handover.cancelled')->sole()->causer_id)->toBe($this->emeka->id)
        ->and(offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm])->status)->toBe(HandoverStatus::Pending);
});

it('rejects invalid transitions and changes nothing', function (array $first, string $then, string $message) {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 30);
    $incoming = fn () => incomingHandover($this->agency, $handover);

    $steps = [
        'accept' => fn () => acceptHandover($this->ada, $this->agency, $incoming()),
        'decline' => fn () => declineHandover($this->ada, $this->agency, $incoming()),
        'cancel' => fn () => withdrawHandover($this->ngozi, $this->landlord, $handover),
        'expire' => function () use ($handover) {
            $this->travel(15)->days();

            return inOrganization($this->landlord, fn () => app(HandoverService::class)->expire($handover));
        },
        'landlord notice' => fn () => giveHandoverNotice($this->ngozi, $this->landlord, $handover),
        'agency notice' => fn () => giveHandoverNotice($this->ada, $this->agency, $incoming()),
        'landlord withdraws notice' => fn () => withdrawHandoverNotice($this->ngozi, $this->landlord, $handover),
        'agency withdraws notice' => fn () => withdrawHandoverNotice($this->ada, $this->agency, $incoming()),
        'end' => fn () => inOrganization($this->landlord, fn () => app(HandoverService::class)->end(storedHandover($handover))),
        'time passes' => fn () => $this->travel(15)->days(),
        'notice runs out' => fn () => $this->travel(31)->days(),
        'ended by the job' => function () {
            $this->travel(31)->days();
            runHandoverJob('handovers-end');
        },
    ];

    foreach ($first as $step) {
        $steps[$step]();
    }

    $before = storedHandover($handover)->getAttributes();
    $invitationBefore = inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->getAttributes());
    $places = organizationsOf('properties', [$this->palm->id]);
    $audits = AuditEntry::query()->count();
    $notifications = count(Notification::sentNotifications(), COUNT_RECURSIVE);

    expect(fn () => $steps[$then]())->toThrow(HandoverRefused::class, $message);

    expect(storedHandover($handover)->getAttributes())->toBe($before)
        ->and(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->getAttributes()))->toBe($invitationBefore)
        ->and(organizationsOf('properties', [$this->palm->id]))->toBe($places)
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(count(Notification::sentNotifications(), COUNT_RECURSIVE))->toBe($notifications);
})->with([
    'accept after decline' => [['decline'], 'accept', 'declined'],
    'decline after accept' => [['accept'], 'decline', 'being managed'],
    'accept twice' => [['accept'], 'accept', 'being managed'],
    'accept after withdrawal' => [['cancel'], 'accept', 'withdrawn'],
    'withdraw after accept' => [['accept'], 'cancel', 'being managed'],
    'withdraw after decline' => [['decline'], 'cancel', 'declined'],
    'expire after accept' => [['accept'], 'expire', 'being managed'],
    'accept after expiry' => [['expire'], 'accept', 'expired'],
    'accept once expired, not yet recorded' => [['time passes'], 'accept', 'expired'],
    'decline once expired, not yet recorded' => [['time passes'], 'decline', 'expired'],
    'withdraw once expired, not yet recorded' => [['time passes'], 'cancel', 'expired'],
    'notice before acceptance' => [[], 'landlord notice', 'waiting for an answer'],
    'notice twice' => [['accept', 'landlord notice'], 'agency notice', 'Notice was given'],
    'withdraw notice nobody gave' => [['accept'], 'landlord withdraws notice', 'being managed'],
    'withdraw the other side\'s notice' => [['accept', 'landlord notice'], 'agency withdraws notice', 'Only the side that gave notice'],
    'withdraw notice after it ran out' => [['accept', 'agency notice', 'notice runs out'], 'agency withdraws notice', 'ran out'],
    'end before the end date' => [['accept', 'landlord notice'], 'end', 'goes on until'],
    'end without notice' => [['accept'], 'end', 'being managed'],
    'notice after the end' => [['accept', 'landlord notice', 'ended by the job'], 'agency notice', 'ended'],
    'withdraw notice after the end' => [['accept', 'landlord notice', 'ended by the job'], 'landlord withdraws notice', 'ended'],
    'accept after the end' => [['accept', 'landlord notice', 'ended by the job'], 'accept', 'ended'],
]);

it('refuses to expire an offer before its 14 days', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $this->travel(13)->days();

    expect(fn () => inOrganization($this->landlord, fn () => app(HandoverService::class)->expire($handover)))->toThrow(HandoverRefused::class, 'still open')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);
});

it('refuses an answer from the wrong organization and a withdrawal by the receiver', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $other = Organization::factory()->create(['slug' => 'bello-homes']);
    $dayo = joinAs(User::factory()->create(), $other);
    $before = storedHandover($handover)->getAttributes();

    expect(incomingHandover($other, $handover))->toBeNull()
        ->and(fn () => acceptHandover($dayo, $other, $handover, Money::naira(12_100)))->toThrow(AuthorizationException::class)
        ->and(fn () => declineHandover($dayo, $other, $handover))->toThrow(AuthorizationException::class)
        ->and(fn () => giveHandoverNotice($dayo, $other, $handover))->toThrow(AuthorizationException::class)
        // The offering organization may not answer its own offer…
        ->and(fn () => acceptHandover($this->ngozi, $this->landlord, $handover, Money::naira(12_100)))->toThrow(AuthorizationException::class)
        ->and(fn () => declineHandover($this->ngozi, $this->landlord, $handover))->toThrow(AuthorizationException::class)
        // …and the receiving one may not withdraw it.
        ->and(fn () => withdrawHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover)))->toThrow(AuthorizationException::class)
        // An owner of the receiving organization acting from another workspace is refused too.
        ->and(fn () => acceptHandover($this->ada, $this->landlord, $handover, Money::naira(12_100)))->toThrow(AuthorizationException::class);

    expect(storedHandover($handover)->getAttributes())->toBe($before)
        ->and(AuditEntry::query()->where('log_name', HandoverService::LOG)->count())->toBe(1);
});

it('lets only org-owners offer, answer and give or withdraw notice', function (OrganizationRole $role) {
    $landlordMember = joinAs(User::factory()->create(), $this->landlord, $role);
    $agencyMember = joinAs(User::factory()->create(), $this->agency, $role);

    expect(fn () => offerHandover($landlordMember, $this->landlord, $this->agency, [$this->palm]))->toThrow(AuthorizationException::class)
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(0);

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);

    expect(fn () => acceptHandover($agencyMember, $this->agency, incomingHandover($this->agency, $handover)))->toThrow(AuthorizationException::class)
        ->and(fn () => declineHandover($agencyMember, $this->agency, incomingHandover($this->agency, $handover)))->toThrow(AuthorizationException::class)
        ->and(fn () => withdrawHandover($landlordMember, $this->landlord, $handover))->toThrow(AuthorizationException::class)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);

    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));

    expect(fn () => giveHandoverNotice($landlordMember, $this->landlord, $handover))->toThrow(AuthorizationException::class)
        ->and(fn () => giveHandoverNotice($agencyMember, $this->agency, incomingHandover($this->agency, $handover)))->toThrow(AuthorizationException::class)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Active);

    // Only owners hold the permission (ARCHITECTURE.md § 5); never while staff impersonate, nor in a suspended organization.
    expect(OrganizationRole::Owner->permissions())->toContain(OrganizationPermission::ManageHandovers)
        ->and(collect(OrganizationRole::cases())->reject(fn ($r) => $r === OrganizationRole::Owner)->filter(fn ($r) => in_array(OrganizationPermission::ManageHandovers, $r->permissions(), true))->all())->toBe([])
        ->and(OrganizationPermission::ManageHandovers->blockedWhileImpersonating())->toBeTrue()
        ->and(OrganizationPermission::ManageHandovers->allowedWhileSuspended())->toBeFalse();
})->with([
    'administrator' => [OrganizationRole::Admin],
    'agent' => [OrganizationRole::Agent],
    'property owner' => [OrganizationRole::PropertyOwner],
    'tenant' => [OrganizationRole::Tenant],
]);

it('refuses every step in a suspended organization', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $this->agency->forceFill(['status' => OrganizationStatus::Suspended])->save();

    expect(fn () => acceptHandover($this->ada, $this->agency->fresh(), incomingHandover($this->agency, $handover), Money::naira(12_100)))->toThrow(AuthorizationException::class)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);

    $this->landlord->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect(fn () => withdrawHandover($this->ngozi, $this->landlord->fresh(), $handover))->toThrow(AuthorizationException::class)
        ->and(fn () => offerHandover($this->ngozi, $this->landlord->fresh(), $this->agency, [$this->lagoon]))->toThrow(AuthorizationException::class);
});

it('expires unanswered offers after 14 days through the hourly job', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $answered = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $answered));

    $this->travelTo(now()->addDays(14)->subMinute());
    runHandoverJob('handovers-expire');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);

    $this->travel(2)->minutes();
    runHandoverJob('handovers-expire');

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Expired)
        ->and(storedHandover($handover)->responded_at)->toBeNull()
        ->and(inOrganization($this->landlord, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->status))->toBe(InvitationStatus::Expired)
        ->and(storedHandover($answered)->status)->toBe(HandoverStatus::Active)
        ->and(AuditEntry::query()->where('event', 'handover.expired')->sole()->causer_id)->toBeNull();
    Notification::assertSentToTimes($this->ngozi, HandoverChanged::class, 1 + 2 + 1);

    // Running it again changes nothing and tells nobody again.
    runHandoverJob('handovers-expire');
    expect(AuditEntry::query()->where('event', 'handover.expired')->count())->toBe(1);
    Notification::assertSentToTimes($this->ngozi, HandoverChanged::class, 4);
});

it('tells both sides once per transition, by email and in the app', function () {
    $sent = fn (User $user, string $event): int => Notification::sent($user, HandoverChanged::class, fn (HandoverChanged $n) => $n->event === $event)->count();

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 10);

    // Offered: each receiving owner gets the link, once; each offering owner is told, once.
    Notification::assertSentToTimes($this->ada, HandoverOffered::class, 1);
    Notification::assertSentToTimes($this->chika, HandoverOffered::class, 1);
    Notification::assertNotSentTo([$this->ngozi, $this->emeka], HandoverOffered::class);
    expect($sent($this->ngozi, 'handover.offered'))->toBe(1)->and($sent($this->emeka, 'handover.offered'))->toBe(1)->and($sent($this->ada, 'handover.offered'))->toBe(0);
    Notification::assertSentTo($this->ada, HandoverOffered::class, fn (HandoverOffered $n, array $channels) => $channels === ['mail', 'database'] && $n->buildings === 1 && $n->units === 3 && $n->fromOrganization === 'Eze Properties');

    $steps = [
        'handover.accepted' => fn () => acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover)),
        'handover.notice_given' => fn () => giveHandoverNotice($this->chika, $this->agency, incomingHandover($this->agency, $handover)),
        'handover.notice_withdrawn' => fn () => withdrawHandoverNotice($this->chika, $this->agency, incomingHandover($this->agency, $handover)),
        'handover.ended' => function () use ($handover) {
            giveHandoverNotice($this->ngozi, $this->landlord, $handover);
            $this->travel(11)->days();
            runHandoverJob('handovers-end');
        },
    ];

    foreach ($steps as $event => $step) {
        $step();

        foreach ([$this->ngozi, $this->emeka, $this->ada, $this->chika] as $owner) {
            expect($sent($owner, $event))->toBe(1);
        }
    }

    // Declined and withdrawn offers too.
    $second = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    declineHandover($this->chika, $this->agency, incomingHandover($this->agency, $second));
    $third = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    withdrawHandover($this->ngozi, $this->landlord, $third);

    foreach (['handover.declined', 'handover.cancelled'] as $event) {
        foreach ([$this->ngozi, $this->emeka, $this->ada, $this->chika] as $owner) {
            expect($sent($owner, $event))->toBe(1);
        }
    }

    // No SMS or WhatsApp channel anywhere.
    foreach (Notification::sentNotifications() as $byNotifiable) {
        foreach ($byNotifiable as $byClass) {
            foreach ($byClass as $notifications) {
                foreach ($notifications as $notification) {
                    expect($notification['channels'])->toBe(['mail', 'database']);
                }
            }
        }
    }
});

it('tells someone who owns both organizations once per transition', function () {
    joinAs($this->ada, $this->landlord);
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    acceptHandover($this->chika, $this->agency, incomingHandover($this->agency, $handover));

    expect(Notification::sent($this->ada, HandoverChanged::class, fn (HandoverChanged $n) => $n->event === 'handover.accepted')->count())->toBe(1);
});

it('stores the in-app copy of a notification, linking to the workspace page and never the token', function () {
    Notification::swap(new ChannelManager(app()));
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);

    $offer = json_decode(DB::table('notifications')->where('notifiable_id', $this->ada->id)->sole()->data, true);
    expect($offer)->toMatchArray(['event' => 'handover.offered', 'handover_id' => $handover->id, 'from_organization' => 'Eze Properties', 'buildings' => 1, 'units' => 3])
        ->and($offer['url'])->toBe(url('/o/acme-homes/handovers/'.$handover->id))
        ->and(json_encode($offer))->not->toContain(hash('sha256', 'x'));

    $told = json_decode(DB::table('notifications')->where('notifiable_id', $this->ngozi->id)->sole()->data, true);
    expect($told['url'])->toBe(url('/o/eze-properties/handovers/'.$handover->id));
});

it('offers only the landlord\'s active buildings that are in no open handover', function () {
    $archived = buildingIn($this->landlord, ['name' => 'Old Mill', 'slug' => 'old-mill', 'address' => '2 Mill Road', 'status' => PropertyStatus::Archived]);
    $theirs = buildingIn($this->agency, ['name' => 'Their Block', 'slug' => 'their-block', 'address' => '3 Their Road']);
    $picker = fn (): array => inOrganization($this->landlord, fn () => app(OfferableBuildings::class)->options());

    expect($picker())->toBe([$this->lagoon->id => 'Lagoon View', $this->palm->id => 'Palm Court']);

    expect(fn () => offerHandover($this->ngozi, $this->landlord, $this->agency, [$archived]))->toThrow(HandoverRefused::class, 'Only your active buildings')
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $this->agency, [$theirs]))->toThrow(HandoverRefused::class, 'Only your active buildings')
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $this->agency, []))->toThrow(HandoverRefused::class, 'at least one building');

    // A building in an open (waiting) handover cannot be offered again, to anyone.
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    expect($picker())->toBe([$this->lagoon->id => 'Lagoon View'])
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $other, [$this->palm, $this->lagoon]))->toThrow(HandoverRefused::class, 'Palm Court is already in an open handover')
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(1);

    // Once accepted it is the agency's (not the landlord's to offer); the agency cannot hand it on either.
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));
    expect($picker())->toBe([$this->lagoon->id => 'Lagoon View'])
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $other, [$this->palm]))->toThrow(HandoverRefused::class, 'Only your active buildings')
        ->and(fn () => offerHandover($this->ada, $this->agency, $other, [$this->palm]))->toThrow(AuthorizationException::class);

    // An offer past its 14 days no longer holds its building.
    $waiting = offerHandover($this->ngozi, $this->landlord, $other, [$this->lagoon]);
    expect($picker())->toBe([]);
    $this->travel(15)->days();
    expect($picker())->toBe([$this->lagoon->id => 'Lagoon View'])
        ->and(storedHandover($waiting)->status)->toBe(HandoverStatus::Pending);
});

it('refuses an offer to itself or to an organization that does not manage buildings, a notice period out of range, and the landlord collecting rent', function () {
    $owners = Organization::factory()->create(['slug' => 'oke-family', 'name' => 'Oke Family', 'type' => OrganizationType::PropertyOwner]);
    $suspended = Organization::factory()->create(['slug' => 'shut-agency', 'status' => OrganizationStatus::Suspended]);

    expect(fn () => offerHandover($this->ngozi, $this->landlord, $this->landlord, [$this->palm]))->toThrow(HandoverRefused::class, 'already manages these buildings')
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $owners, [$this->palm]))->toThrow(HandoverRefused::class, HandoverTargets::NOT_FOUND)
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $suspended, [$this->palm]))->toThrow(HandoverRefused::class, HandoverTargets::NOT_FOUND)
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 181))->toThrow(HandoverRefused::class, 'between 0 and 180')
        ->and(fn () => offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: -1))->toThrow(HandoverRefused::class, 'between 0 and 180')
        ->and(fn () => inOrganization($this->landlord, fn () => app(HandoverService::class)->offer($this->ngozi, $this->agency, [$this->palm->id], 30, RentCollection::Owner)))->toThrow(HandoverRefused::class, 'the agency collects rent')
        // An agency does not hand its own buildings over (only a landlord's organization does, for now).
        ->and(fn () => offerHandover($this->ada, $this->agency, $this->landlord, [(int) DB::table('properties')->where('organization_id', $this->agency->id)->value('id')]))->toThrow(AuthorizationException::class)
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->count()))->toBe(0);

    // 0 and 180 days are allowed.
    expect(offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 0)->notice_days)->toBe(0)
        ->and(offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon], noticeDays: 180)->notice_days)->toBe(180);
});

it('refuses an acceptance when the bill changed since the accept screen showed it', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $shown = billAfter($handover);
    expect($shown)->toEqual(Money::naira(11_400));

    // The landlord adds a flat to Palm Court meanwhile: 13 units, ₦12,100.
    unitIn($this->landlord, $this->palm, ['unit_label' => 'Flat 4']);

    expect(fn () => acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover), $shown))
        ->toThrow(HandoverRefused::class, 'it would now be ₦12,100 a month')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->landlord->id]);

    expect(acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover), Money::naira(12_100))->status)->toBe(HandoverStatus::Active)
        ->and(storedHandover($handover)->billable_units)->toBe(4)
        ->and(storedHandover($handover)->buildings->first()->unit_count)->toBe(4);
});

it('refuses an acceptance when a building was archived meanwhile', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    inOrganization($this->landlord, fn () => Property::query()->whereKey($this->lagoon->id)->update(['status' => PropertyStatus::Archived]));

    expect(fn () => acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover), Money::naira(12_800)))->toThrow(HandoverRefused::class, 'no longer has one of these buildings')
        ->and(array_unique(organizationsOf('properties', [$this->palm->id, $this->lagoon->id])))->toBe([$this->palm->id => $this->landlord->id]);
});

it('ends a management with no notice at the next hourly run, and lets the agency resign too', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm], noticeDays: 0);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));
    giveHandoverNotice($this->chika, $this->agency, incomingHandover($this->agency, $handover));

    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Ending)
        ->and($stored->notice_given_by_organization_id)->toBe($this->agency->id)
        ->and($stored->notice_given_by_user_id)->toBe($this->chika->id)
        ->and($stored->ends_at?->toIso8601String())->toBe(now()->toIso8601String());

    $this->travel(1)->hours();
    runHandoverJob('handovers-end');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ended)
        ->and(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->landlord->id]);
});

it('lets the side that gave notice withdraw it before the end date, and management goes on', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $handover));
    giveHandoverNotice($this->ngozi, $this->landlord, $handover);
    $this->travel(29)->days();

    withdrawHandoverNotice($this->emeka, $this->landlord, $handover);
    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Active)
        ->and($stored->ends_at)->toBeNull()
        ->and($stored->notice_given_by_organization_id)->toBeNull();

    $this->travel(5)->days();
    runHandoverJob('handovers-end');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Active)
        ->and(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->agency->id]);
});

it('finds agencies on GoProperty by web address or an owner\'s email, and nothing else', function () {
    $targets = app(HandoverTargets::class);
    $admin = joinAs(User::factory()->create(['email' => 'admin@adeyemi.ng']), $this->agency, OrganizationRole::Admin);
    $this->agency->forceFill(['previous_slugs' => ['adeyemi-homes']])->save();

    expect($targets->find('https://goproperty.ng/o/acme-homes')?->id)->toBe($this->agency->id)
        ->and($targets->find('goproperty.ng/o/acme-homes/buildings?x=1')?->id)->toBe($this->agency->id)
        ->and($targets->find('  Acme-Homes ')?->id)->toBe($this->agency->id)
        ->and($targets->find('adeyemi-homes')?->id)->toBe($this->agency->id)
        ->and($targets->find('ADA.OWNER@example.com')?->id)->toBe($this->agency->id)
        // An administrator is not an owner; a landlord's organization takes no handovers; unknown ones find nothing.
        ->and($targets->find('admin@adeyemi.ng'))->toBeNull()
        ->and($targets->find('eze-properties'))->toBeNull()
        ->and($targets->find('ngozi.eze@example.com'))->toBeNull()
        ->and($targets->find('nobody@example.com'))->toBeNull()
        ->and($targets->find("acme-homes'; drop table"))->toBeNull()
        ->and($targets->find(''))->toBeNull();

    $this->agency->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect($targets->find('acme-homes'))->toBeNull();
});

it('keeps the incoming list to the handovers offered to this organization', function () {
    $other = Organization::factory()->create(['slug' => 'bello-homes', 'name' => 'Bello Homes']);
    $otherLandlord = Organization::factory()->create(['slug' => 'oke-family', 'name' => 'Oke Family', 'type' => OrganizationType::PropertyOwner]);
    $oke = joinAs(User::factory()->create(), $otherLandlord);
    $okeBuilding = buildingIn($otherLandlord, ['name' => 'Oke House', 'slug' => 'oke-house', 'address' => '5 Oke Street']);

    $first = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    $second = offerHandover($oke, $otherLandlord, $this->agency, [$okeBuilding]);
    $elsewhere = offerHandover($this->ngozi, $this->landlord, $other, [$this->lagoon]);
    declineHandover($this->ada, $this->agency, incomingHandover($this->agency, $first));

    $incoming = inOrganization($this->agency, fn () => app(IncomingHandovers::class)->recent());
    expect($incoming->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($incoming->first()->organization->is($otherLandlord))->toBeTrue()
        ->and(inOrganization($other, fn () => app(IncomingHandovers::class)->recent())->pluck('id')->all())->toBe([$elsewhere->id])
        ->and(inOrganization($this->landlord, fn () => app(IncomingHandovers::class)->recent()))->toBeEmpty();
});
