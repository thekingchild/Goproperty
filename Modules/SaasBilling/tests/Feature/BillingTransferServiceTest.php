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
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Enums\BillingTransferStatus;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Notifications\BillingTransferAnswered;
use Modules\SaasBilling\Notifications\BillingTransferOffered;
use Modules\SaasBilling\Notifications\BillingTransferWithdrawn;
use Modules\SaasBilling\Transfers\BillingTransferRefused;
use Modules\SaasBilling\Transfers\BillingTransferService;
use Modules\SaasBilling\Transfers\IncomingTransfers;
use Modules\SaasBilling\Transfers\MonthlyBills;
use Modules\SaasBilling\Transfers\TransferTargets;

require_once __DIR__.'/helpers.php';

/*
 * P02-04: the billing-transfer state machine (BILLING_PAYMENTS.md § 7),
 * recorded only through BillingTransferService: offer, accept, decline,
 * withdraw, expire after 14 days; invalid transitions refused with nothing
 * changed; only org-owners act; one notification set per transition;
 * accepting moves no payer and gives no access (P02 decision 5).
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();

    // A property owner's own organization, on Starter with 30 units:
    // ₦10,000 + 20 × ₦700 = ₦24,000 a month (BILLING_PAYMENTS.md § 1.1).
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes', 'type' => OrganizationType::PropertyOwner, 'signup_plan_key' => 'starter']);
    $this->ada = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    billableUnits($this->acme, 30);

    $this->agency = Organization::factory()->create(['slug' => 'bright-agency', 'name' => 'Bright Agency']);
    $this->bola = joinAs(User::factory()->create(['name' => 'Bola Owner']), $this->agency);
    $this->chika = joinAs(User::factory()->create(['name' => 'Chika Owner']), $this->agency);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('records an offer, an acceptance and a decline through BillingTransferService', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);

    $stored = storedTransfer($offer);
    expect($stored->status)->toBe(BillingTransferStatus::Pending)
        ->and($stored->organization_id)->toBe($this->acme->id)
        ->and($stored->to_organization_id)->toBe($this->agency->id)
        ->and($stored->initiated_by_user_id)->toBe($this->ada->id)
        ->and($stored->subscription_id)->toBeNull()
        ->and($stored->expires_at?->toDateString())->toBe(now()->addDays(14)->toDateString());

    // The link is a Core invitation of its own kind, owned by the offering organization.
    $invitation = inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole());
    expect($invitation->kind)->toBe(InvitationKind::OrgBillingTransfer)
        ->and($invitation->target_organization_id)->toBe($this->agency->id)
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->email)->toBeNull()
        ->and($invitation->roles)->toBe([])
        ->and($invitation->token_hash)->toBe(hash('sha256', offerToken($this->bola)))
        ->and($invitation->expires_at?->equalTo($stored->expires_at))->toBeTrue();

    // The receiving organization finds it; the offering one does not see it as incoming.
    expect(incomingOffer($this->agency, $offer)?->id)->toBe($offer->id)
        ->and(incomingOffer($this->acme, $offer))->toBeNull();

    $accepted = acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer));
    $stored = storedTransfer($offer);
    expect($accepted->status)->toBe(BillingTransferStatus::Completed)
        ->and($stored->status)->toBe(BillingTransferStatus::Completed)
        ->and($stored->responded_by_user_id)->toBe($this->bola->id)
        ->and($stored->responded_at)->not->toBeNull()
        ->and($stored->monthly_amount_kobo?->kobo)->toBe(2_400_000)
        ->and($stored->billable_units)->toBe(30)
        ->and($stored->plan_key)->toBe('starter')
        ->and($stored->completed_at)->toBeNull();
    $invitation = inOrganization($this->acme, fn () => Invitation::query()->findOrFail($invitation->id));
    expect($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->accepted_by_user_id)->toBe($this->bola->id);

    // A second offer, declined.
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $dayo = joinAs(User::factory()->create(), $other);
    $second = offerBill($this->ada, $this->acme, $other);
    declineOffer($dayo, $other, incomingOffer($other, $second));
    $stored = storedTransfer($second);
    expect($stored->status)->toBe(BillingTransferStatus::Declined)
        ->and($stored->responded_by_user_id)->toBe($dayo->id)
        ->and($stored->monthly_amount_kobo)->toBeNull()
        ->and(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $second->id)->sole()->status))->toBe(InvitationStatus::Declined);

    // Each step is in the audit log, the transfer as subject, the actor as causer.
    $entries = AuditEntry::query()->where('log_name', BillingTransferService::LOG)->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['billing_transfer.offered', 'billing_transfer.accepted', 'billing_transfer.offered', 'billing_transfer.declined'])
        ->and($entries->pluck('causer_id')->all())->toBe([$this->ada->id, $this->bola->id, $this->ada->id, $dayo->id])
        ->and($entries[1]->subject_id)->toBe($offer->id)
        ->and($entries[1]->properties['monthly_amount_kobo'])->toBe(2_400_000)
        ->and($entries[1]->properties['to_organization_id'])->toBe($this->agency->id);
});

it('records a withdrawal by the offering organization', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    withdrawOffer($this->ada, $this->acme, $offer);

    $stored = storedTransfer($offer);
    expect($stored->status)->toBe(BillingTransferStatus::Cancelled)
        ->and($stored->cancelled_at)->not->toBeNull()
        ->and(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->status))->toBe(InvitationStatus::Cancelled)
        ->and(AuditEntry::query()->where('event', 'billing_transfer.cancelled')->sole()->causer_id)->toBe($this->ada->id);

    // A new offer may follow once the first is closed.
    expect(offerBill($this->ada, $this->acme, $this->agency)->status)->toBe(BillingTransferStatus::Pending);
});

it('rejects invalid transitions and changes nothing', function (string $first, string $then, string $message) {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $incoming = fn () => incomingOffer($this->agency, $offer);

    $steps = [
        'accept' => fn () => acceptOffer($this->bola, $this->agency, $incoming()),
        'decline' => fn () => declineOffer($this->bola, $this->agency, $incoming()),
        'cancel' => fn () => withdrawOffer($this->ada, $this->acme, $offer),
        'expire' => function () use ($offer) {
            $this->travel(15)->days();

            return inOrganization($this->acme, fn () => app(BillingTransferService::class)->expire($offer));
        },
        'time passes' => fn () => $this->travel(15)->days(),
    ];

    $steps[$first]();
    $before = storedTransfer($offer)->getAttributes();
    $invitationBefore = inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->getAttributes());
    $audits = AuditEntry::query()->count();
    $notifications = count(Notification::sentNotifications());

    expect(fn () => $steps[$then]())->toThrow(BillingTransferRefused::class, $message);

    expect(storedTransfer($offer)->getAttributes())->toBe($before)
        ->and(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->getAttributes()))->toBe($invitationBefore)
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(count(Notification::sentNotifications()))->toBe($notifications);
})->with([
    'accept after decline' => ['decline', 'accept', 'declined'],
    'decline after accept' => ['accept', 'decline', 'already accepted'],
    'accept twice' => ['accept', 'accept', 'already accepted'],
    'accept after withdrawal' => ['cancel', 'accept', 'withdrawn'],
    'withdraw after accept' => ['accept', 'cancel', 'already accepted'],
    'withdraw after decline' => ['decline', 'cancel', 'declined'],
    'expire after accept' => ['accept', 'expire', 'already accepted'],
    'accept after expiry' => ['expire', 'accept', 'expired'],
    'accept once expired, not yet recorded' => ['time passes', 'accept', 'expired'],
    'decline once expired, not yet recorded' => ['time passes', 'decline', 'expired'],
    'withdraw once expired, not yet recorded' => ['time passes', 'cancel', 'expired'],
]);

it('refuses to expire an offer before its 14 days', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $this->travel(13)->days();

    expect(fn () => inOrganization($this->acme, fn () => app(BillingTransferService::class)->expire($offer)))->toThrow(BillingTransferRefused::class, 'still open')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);
});

it('refuses an answer from the wrong organization and a withdrawal by the receiver', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $dayo = joinAs(User::factory()->create(), $other);
    $before = storedTransfer($offer)->getAttributes();

    // Another organization never finds the offer, and may not answer it even holding the row.
    expect(incomingOffer($other, $offer))->toBeNull()
        ->and(fn () => acceptOffer($dayo, $other, $offer))->toThrow(AuthorizationException::class)
        ->and(fn () => declineOffer($dayo, $other, $offer))->toThrow(AuthorizationException::class)
        // The offering organization may not answer its own offer…
        ->and(fn () => acceptOffer($this->ada, $this->acme, $offer))->toThrow(AuthorizationException::class)
        ->and(fn () => declineOffer($this->ada, $this->acme, $offer))->toThrow(AuthorizationException::class)
        // …and the receiving one may not withdraw it.
        ->and(fn () => withdrawOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer)))->toThrow(AuthorizationException::class)
        // An owner of the receiving organization acting from another workspace is refused too.
        ->and(fn () => acceptOffer($this->bola, $this->acme, $offer))->toThrow(AuthorizationException::class);

    expect(storedTransfer($offer)->getAttributes())->toBe($before)
        ->and(AuditEntry::query()->where('log_name', BillingTransferService::LOG)->count())->toBe(1);
});

it('lets only org-owners offer and answer', function (OrganizationRole $role) {
    $acmeMember = joinAs(User::factory()->create(), $this->acme, $role);
    $agencyMember = joinAs(User::factory()->create(), $this->agency, $role);

    expect(fn () => offerBill($acmeMember, $this->acme, $this->agency))->toThrow(AuthorizationException::class)
        ->and(inOrganization($this->acme, fn () => BillingTransferRequest::query()->count()))->toBe(0);

    $offer = offerBill($this->ada, $this->acme, $this->agency);

    expect(fn () => acceptOffer($agencyMember, $this->agency, incomingOffer($this->agency, $offer)))->toThrow(AuthorizationException::class)
        ->and(fn () => declineOffer($agencyMember, $this->agency, incomingOffer($this->agency, $offer)))->toThrow(AuthorizationException::class)
        ->and(fn () => withdrawOffer($acmeMember, $this->acme, $offer))->toThrow(AuthorizationException::class)
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);

    // Only owners hold the permission (ARCHITECTURE.md § 5), kept for paying while suspended,
    // never while GoProperty staff impersonate.
    expect(OrganizationRole::Owner->permissions())->toContain(OrganizationPermission::ManageBilling)
        ->and(collect(OrganizationRole::cases())->reject(fn ($r) => $r === OrganizationRole::Owner)->filter(fn ($r) => in_array(OrganizationPermission::ManageBilling, $r->permissions(), true))->all())->toBe([])
        ->and(OrganizationPermission::ManageBilling->blockedWhileImpersonating())->toBeTrue();
})->with([
    'administrator' => [OrganizationRole::Admin],
    'agent' => [OrganizationRole::Agent],
    'property owner' => [OrganizationRole::PropertyOwner],
    'tenant' => [OrganizationRole::Tenant],
]);

it('expires unanswered offers after 14 days through the scheduled job', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $answered = offerBill($this->bola, $this->agency, $this->acme);
    acceptOffer($this->ada, $this->acme, incomingOffer($this->acme, $answered));

    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === 'billing-transfers-expire');
    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('20 * * * *');

    $this->travelTo(now()->addDays(14)->subMinute());
    $event->run(app());
    expect(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);
    Notification::assertNotSentTo($this->ada, BillingTransferAnswered::class, fn ($n) => $n->outcome === BillingTransferStatus::Expired);

    $this->travel(2)->minutes();
    $event->run(app());

    $stored = storedTransfer($offer);
    expect($stored->status)->toBe(BillingTransferStatus::Expired)
        ->and($stored->responded_at)->toBeNull()
        ->and(inOrganization($this->acme, fn () => Invitation::query()->where('billing_transfer_id', $offer->id)->sole()->status))->toBe(InvitationStatus::Expired)
        ->and(storedTransfer($answered)->status)->toBe(BillingTransferStatus::Completed)
        ->and(AuditEntry::query()->where('event', 'billing_transfer.expired')->sole()->causer_id)->toBeNull();
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 1);

    // Running it again changes nothing and tells nobody again.
    $event->run(app());
    expect(AuditEntry::query()->where('event', 'billing_transfer.expired')->count())->toBe(1);
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 1);
});

it('writes no payer field and gives no access when an offer is accepted', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $acmeBefore = $this->acme->fresh()?->getAttributes();
    $agencyBefore = $this->agency->fresh()?->getAttributes();
    $memberships = DB::table('organization_user')->orderBy('id')->get()->all();
    $roles = DB::table('model_has_roles')->orderBy('id')->get()->all();

    acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer));

    // No payer column exists yet (P06-06 adds the switch); the transfer stays not "completed_at".
    expect(Schema::getColumnListing('billing_transfer_requests'))->not->toContain('payer_organization_id')
        ->and(Schema::hasTable('subscriptions'))->toBeFalse()
        ->and(storedTransfer($offer)->completed_at)->toBeNull()
        ->and($this->acme->fresh()?->getAttributes())->toBe($acmeBefore)
        ->and($this->agency->fresh()?->getAttributes())->toBe($agencyBefore)
        // Nobody joined either organization and no role was granted.
        ->and(DB::table('organization_user')->orderBy('id')->get()->all())->toEqual($memberships)
        ->and(DB::table('model_has_roles')->orderBy('id')->get()->all())->toEqual($roles);

    // The agency's owner still cannot open the owner's workspace or its buildings.
    transfersFreshRequest();
    $this->actingAs($this->bola)->get('/o/acme-homes/buildings')->assertNotFound();
    expect(inOrganization($this->agency, fn () => Unit::query()->count()))->toBe(0);
});

it('notifies once per transition, by email and in the app', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);

    // Offered: each owner of the receiving organization, once; nobody else.
    Notification::assertSentToTimes($this->bola, BillingTransferOffered::class, 1);
    Notification::assertSentToTimes($this->chika, BillingTransferOffered::class, 1);
    Notification::assertNotSentTo($this->ada, BillingTransferOffered::class);
    Notification::assertSentTo($this->bola, BillingTransferOffered::class, fn (BillingTransferOffered $n, array $channels) => $channels === ['mail', 'database']
        && $n->monthlyAmount === '₦24,000' && $n->fromOrganization === 'Acme Homes');

    // Accepted: the owner who offered, once.
    acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer));
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 1);
    Notification::assertSentTo($this->ada, BillingTransferAnswered::class, fn (BillingTransferAnswered $n, array $channels) => $n->outcome === BillingTransferStatus::Completed
        && $channels === ['mail', 'database'] && $n->monthlyAmount === '₦24,000');
    Notification::assertNotSentTo([$this->bola, $this->chika], BillingTransferAnswered::class);

    // Declined: the owner who offered, once more.
    $second = offerBill($this->ada, $this->acme, $this->agency);
    declineOffer($this->chika, $this->agency, incomingOffer($this->agency, $second));
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 2);
    Notification::assertSentTo($this->ada, BillingTransferAnswered::class, fn (BillingTransferAnswered $n) => $n->outcome === BillingTransferStatus::Declined && $n->transferId === $second->id);

    // Withdrawn: the receiving owners, once each.
    $third = offerBill($this->ada, $this->acme, $this->agency);
    withdrawOffer($this->ada, $this->acme, $third);
    Notification::assertSentToTimes($this->bola, BillingTransferWithdrawn::class, 1);
    Notification::assertSentToTimes($this->chika, BillingTransferWithdrawn::class, 1);
    Notification::assertSentToTimes($this->ada, BillingTransferAnswered::class, 2);

    // No SMS or WhatsApp channel anywhere.
    foreach (Notification::sentNotifications() as $byNotifiable) {
        foreach ($byNotifiable as $byClass) {
            foreach ($byClass as $notifications) {
                foreach ($notifications as $sent) {
                    expect($sent['channels'])->toBe(['mail', 'database']);
                }
            }
        }
    }
});

it('tells the organization\'s owners when the owner who offered is no longer one', function () {
    $eze = joinAs(User::factory()->create(), $this->acme);
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    inOrganization($this->acme, fn () => Membership::query()->where('user_id', $this->ada->id)->delete());

    declineOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer));

    Notification::assertSentToTimes($eze, BillingTransferAnswered::class, 1);
    Notification::assertNotSentTo($this->ada, BillingTransferAnswered::class);
});

it('stores the in-app copy of a notification', function () {
    Notification::swap(new ChannelManager(app()));
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer));

    $stored = DB::table('notifications')->where('notifiable_id', $this->ada->id)->sole();
    expect($stored->type)->toBe(BillingTransferAnswered::class)
        ->and(json_decode($stored->data, true))->toMatchArray([
            'event' => 'billing_transfer.accepted',
            'transfer_id' => $offer->id,
            'to_organization' => 'Bright Agency',
            'monthly_amount' => '₦24,000',
        ])
        // The offer's in-app copy links to the workspace page, never to the token.
        ->and(json_decode(DB::table('notifications')->where('notifiable_id', $this->bola->id)->sole()->data, true)['url'])->toBe(url('/o/bright-agency/billing-transfers/'.$offer->id));
});

it('refuses an offer to itself, to an organization that cannot take it, and a second open offer', function () {
    expect(fn () => offerBill($this->ada, $this->acme, $this->acme))->toThrow(BillingTransferRefused::class, 'already pays its own bill');

    $suspended = Organization::factory()->create(['status' => OrganizationStatus::Suspended]);
    expect(fn () => offerBill($this->ada, $this->acme, $suspended))->toThrow(BillingTransferRefused::class, TransferTargets::NOT_FOUND);

    offerBill($this->ada, $this->acme, $this->agency);
    $other = Organization::factory()->create();
    expect(fn () => offerBill($this->ada, $this->acme, $other))->toThrow(BillingTransferRefused::class, 'already offered to Bright Agency');

    // An offer past its date (not yet recorded) does not block a new one.
    $this->travel(15)->days();
    expect(offerBill($this->ada, $this->acme, $other)->status)->toBe(BillingTransferStatus::Pending)
        ->and(inOrganization($this->acme, fn () => BillingTransferRequest::query()->count()))->toBe(2);
});

it('refuses an amount that changed since the accept screen showed it', function () {
    $offer = offerBill($this->ada, $this->acme, $this->agency);
    $shown = monthlyBillOf($this->acme);
    billableUnits($this->acme, 2);

    expect(fn () => acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer), $shown))
        ->toThrow(BillingTransferRefused::class, 'it is now ₦25,400 a month')
        ->and(storedTransfer($offer)->status)->toBe(BillingTransferStatus::Pending);

    expect(acceptOffer($this->bola, $this->agency, incomingOffer($this->agency, $offer), Money::kobo(2_540_000))->status)->toBe(BillingTransferStatus::Completed)
        ->and(storedTransfer($offer)->billable_units)->toBe(32);
});

it('works out the bill from the plan catalogue and the billable units', function () {
    $bill = app(MonthlyBills::class)->for($this->acme);
    expect($bill->plan->key)->toBe('starter')
        ->and($bill->units)->toBe(30)
        ->and($bill->amount->kobo)->toBe(2_400_000)
        ->and($bill->extraUnits())->toBe(20)
        ->and($bill->planChosen)->toBeTrue();

    // No plan chosen yet: the cheapest plan for the units (60 units → Growth, ₦40,000).
    billableUnits($this->agency, 60);
    $bill = app(MonthlyBills::class)->for($this->agency);
    expect($bill->plan->key)->toBe('growth')
        ->and($bill->amount->kobo)->toBe(4_000_000)
        ->and($bill->planChosen)->toBeFalse();
});

it('finds organizations on GoProperty by web address or an owner\'s email, and nothing else', function () {
    $targets = app(TransferTargets::class);
    $admin = joinAs(User::factory()->create(['email' => 'admin@bright.ng']), $this->agency, OrganizationRole::Admin);
    $this->bola->forceFill(['email' => 'bola@bright.ng'])->save();
    $this->agency->forceFill(['previous_slugs' => ['bright-homes']])->save();

    expect($targets->find('https://goproperty.ng/o/bright-agency')?->id)->toBe($this->agency->id)
        ->and($targets->find('goproperty.ng/o/bright-agency/buildings?x=1')?->id)->toBe($this->agency->id)
        ->and($targets->find('  Bright-Agency ')?->id)->toBe($this->agency->id)
        ->and($targets->find('bright-homes')?->id)->toBe($this->agency->id)
        ->and($targets->find('BOLA@bright.ng')?->id)->toBe($this->agency->id)
        // An administrator is not an owner; unknown addresses and slugs find nothing.
        ->and($targets->find('admin@bright.ng'))->toBeNull()
        ->and($targets->find('nobody@bright.ng'))->toBeNull()
        ->and($targets->find('no-such-agency'))->toBeNull()
        ->and($targets->find("bright-agency'; drop table"))->toBeNull()
        ->and($targets->find(''))->toBeNull();

    // An owner of two organizations is ambiguous: the web address is needed.
    joinAs($this->bola, Organization::factory()->create());
    expect($targets->find('bola@bright.ng'))->toBeNull();

    // A suspended organization takes no offers, and says so no differently.
    $this->agency->forceFill(['status' => OrganizationStatus::Suspended])->save();
    expect($targets->find('bright-agency'))->toBeNull();
});

it('keeps the incoming list to the offers made to this organization', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $dayo = joinAs(User::factory()->create(), $other);
    $first = offerBill($this->ada, $this->acme, $this->agency);
    $second = offerBill($dayo, $other, $this->agency);
    $elsewhere = offerBill($this->bola, $this->agency, $other);
    declineOffer($this->bola, $this->agency, incomingOffer($this->agency, $first));

    $incoming = inOrganization($this->agency, fn () => app(IncomingTransfers::class)->recent());
    expect($incoming->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($incoming->first()->organization->is($other))->toBeTrue()
        ->and(inOrganization($other, fn () => app(IncomingTransfers::class)->recent())->pluck('id')->all())->toBe([$elsewhere->id])
        ->and(inOrganization($this->acme, fn () => app(IncomingTransfers::class)->recent()))->toBeEmpty();
});
