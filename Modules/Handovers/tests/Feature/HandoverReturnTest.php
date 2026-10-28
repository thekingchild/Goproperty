<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MovedTenants;
use App\Tenancy\Moves\MovesBuildingRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Handovers\Enums\CreditSettlement;
use Modules\Handovers\Enums\HandoverKind;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverCredits;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Handovers\HandoverTargets;
use Modules\Handovers\Handovers\OfferableBuildings;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverChanged;
use Modules\Handovers\Notifications\HandoverOffered;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/return-world.php';

/*
 * P04-04 (IMPLEMENTATION_PLAN.md § 3; BILLING_PAYMENTS.md § 7.1, § 7.2,
 * product owner 2026-10-28): an agency returns buildings it holds without
 * a handover to the landlord's own organization. A return is one-way:
 * accepting moves exactly the chosen buildings and their live records in
 * one transaction and ends it as `returned`, final (no notice, no end, no
 * withdrawal); only the agency's org-owner offers, only the landlord's
 * accepts; a return goes only to an organization the buildings' linked
 * owner runs; buildings in an open handover cannot be offered; blocked
 * while GoProperty staff impersonate.
 */

beforeEach(fn () => returnWorld());

afterEach(fn () => handoversFreshRequest());

/** The history tables, whose rows never move. */
const RETURN_HISTORY_TABLES = ['bills', 'bill_items', 'payments', 'payment_allocations', 'receipts', 'tenant_credit_entries', 'tenant_credit_balances', 'lease_contracts', 'document_verifications', 'document_counters'];

it('returns exactly the chosen buildings to the landlord\'s own organization in one transaction and ends as returned', function () {
    $history = rowsByOrganization(RETURN_HISTORY_TABLES);
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);

    $offered = storedHandover($handover);
    expect($offered->kind)->toBe(HandoverKind::Return)
        ->and($offered->status)->toBe(HandoverStatus::Pending)
        ->and($offered->organization_id)->toBe($this->agency->id)
        ->and($offered->to_organization_id)->toBe($this->landlord->id)
        ->and($offered->landlord_organization_id)->toBe($this->landlord->id)
        ->and($offered->rent_collected_by)->toBeNull()
        ->and($offered->notice_days)->toBe(0)
        ->and($offered->buildings->map(fn ($b) => [$b->property_id, $b->property_name, $b->unit_count])->all())->toBe([[$this->harbour->id, 'Harbour Point', 3]])
        ->and($offered->tenants->pluck('tenant_name')->all())->toBe(['Kemi Adebayo', 'Tunde Bakare']);
    Notification::assertSentTo($this->ngozi, HandoverOffered::class, fn (HandoverOffered $offer): bool => $offer->isReturn && $offer->toArray($this->ngozi)['event'] === 'handover.return_offered');

    expect(inOrganization($this->landlord, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1);

    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));

    $returned = storedHandover($handover);
    expect($returned->status)->toBe(HandoverStatus::Returned)
        ->and($returned->accepted_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($returned->responded_by_user_id)->toBe($this->ngozi->id);

    // Exactly Harbour Point moved, with its units and live leases; Garden Court, Lone Tower and Office Block stay.
    expect(organizationsOf('properties', [$this->harbour->id, $this->garden->id, $this->lone->id, $this->office->id]))->toBe([
        $this->harbour->id => $this->landlord->id, $this->garden->id => $this->agency->id, $this->lone->id => $this->agency->id, $this->office->id => $this->agency->id,
    ])
        ->and(array_unique(organizationsOf('units', [$this->flatA->id, $this->flatB->id, $this->flatC->id])))->toBe([$this->flatA->id => $this->landlord->id])
        ->and(organizationsOf('units', [$this->gardenUnit->id]))->toBe([$this->gardenUnit->id => $this->agency->id])
        ->and(array_unique(organizationsOf('leases', [$this->tundeLease->id, $this->kemiLease->id])))->toBe([$this->tundeLease->id => $this->landlord->id])
        ->and(inOrganization($this->landlord, fn () => app(UnitDirectory::class)->countBillable()))->toBe(4);

    // The tenants are copied into the landlord's organization (the agency keeps its records with their history).
    $tenants = inOrganization($this->landlord, fn () => TenantProfile::query()->orderBy('phone')->get());
    expect($tenants->pluck('phone')->all())->toBe(['+2348031112222', '+2348034445555'])
        ->and(inOrganization($this->landlord, fn () => Lease::query()->whereKey($this->tundeLease->id)->value('tenant_profile_id')))->toBe($tenants->firstWhere('phone', '+2348034445555')->id)
        ->and(inOrganization($this->agency, fn () => TenantProfile::query()->withTrashed()->whereKey([$this->tunde->id, $this->kemi->id])->count()))->toBe(2);

    // The building gets the landlord's owner record there, made from the organization's details and not linked (Ngozi runs the organization).
    $owner = inOrganization($this->landlord, fn () => OwnerProfile::query()->sole());
    expect($owner->display_name)->toBe('Eze Properties')
        ->and($owner->email)->toBe('ngozi.eze@example.com')
        ->and($owner->user_id)->toBeNull()
        ->and($returned->owner_profile_id)->toBe($owner->id)
        ->and(inOrganization($this->landlord, fn () => Property::query()->findOrFail($this->harbour->id)->owner_profile_id))->toBe($owner->id);

    // Ngozi still owns Garden Court at the agency, so her owner portal link there stays.
    expect($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe(['property-owner'])
        ->and(inOrganization($this->agency, fn () => OwnerProfile::query()->findOrFail($this->ngoziAtAgency->id)->user_id))->toBe($this->ngozi->id);

    // History stays with the agency, which issued it.
    expect(rowsByOrganization(RETURN_HISTORY_TABLES))->toEqual($history);

    // The link is closed as accepted; audited on the handover and in each organization; both sides told.
    expect(inOrganization($this->agency, fn () => Invitation::query()->where('management_handover_id', $handover->id)->sole()->status))->toBe(InvitationStatus::Accepted)
        ->and(AuditEntry::query()->where('log_name', 'handovers')->where('event', 'handover.returned')->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'handover.records_moved_in')->sole()->properties['direction'])->toBe('one_way_return');
    Notification::assertSentTo([$this->ada, $this->ngozi], HandoverChanged::class, fn (HandoverChanged $changed): bool => $changed->toArray($this->ada)['event'] === 'handover.returned');
});

it('unlinks the landlord at the agency once the return leaves them no building there, and matches the landlord\'s owner record by email', function () {
    $existing = ownerIn($this->landlord, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour, $this->garden]);
    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));

    expect(inOrganization($this->landlord, fn () => OwnerProfile::query()->count()))->toBe(1)
        ->and(inOrganization($this->landlord, fn () => Property::query()->whereKey([$this->harbour->id, $this->garden->id])->pluck('owner_profile_id')->unique()->all()))->toBe([$existing->id])
        ->and($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe([])
        ->and(inOrganization($this->agency, fn () => OwnerProfile::query()->findOrFail($this->ngoziAtAgency->id)->user_id))->toBeNull()
        ->and(ownerPortalIds(ownerPortalPage($this->ngozi), 'building'))->toBe([]);
});

it('refuses notice, withdrawal and end on a returned handover, and any other answer, changing nothing', function () {
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));
    $before = storedHandover($handover)->getAttributes();
    $audits = AuditEntry::query()->count();
    Notification::fake();

    $steps = [
        'the agency gives notice' => fn () => giveHandoverNotice($this->ada, $this->agency, $handover),
        'the landlord gives notice' => fn () => giveHandoverNotice($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)),
        'the agency withdraws notice' => fn () => withdrawHandoverNotice($this->ada, $this->agency, $handover),
        'the landlord withdraws notice' => fn () => withdrawHandoverNotice($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)),
        'the hourly end' => fn () => inOrganization($this->agency, fn () => app(HandoverService::class)->end(storedHandover($handover))),
        'accepting again' => fn () => acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)),
        'declining' => fn () => declineHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)),
        'withdrawing the offer' => fn () => withdrawHandover($this->ada, $this->agency, $handover),
    ];

    foreach ($steps as $step) {
        expect($step)->toThrow(HandoverRefused::class);
    }

    expect(fn () => giveHandoverNotice($this->ada, $this->agency, $handover))->toThrow(HandoverRefused::class, 'for good: a return has no notice and no end');

    runHandoverJob('handovers-end');
    $this->travelTo(now()->addYear());
    runHandoverJob('handovers-end');
    runHandoverJob('handovers-expire');

    expect(storedHandover($handover)->getAttributes())->toEqual($before)
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(organizationsOf('properties', [$this->harbour->id]))->toBe([$this->harbour->id => $this->landlord->id]);
    Notification::assertNothingSent();

    // The state machine itself: a returned handover goes nowhere, and a return never becomes a management.
    foreach (HandoverStatus::cases() as $next) {
        expect(HandoverStatus::Returned->canMoveTo($next))->toBeFalse();
    }

    expect(HandoverKind::Return->acceptedStatus())->toBe(HandoverStatus::Returned)
        ->and(HandoverKind::Management->acceptedStatus())->toBe(HandoverStatus::Active)
        ->and(HandoverStatus::Returned->isOpen())->toBeFalse();
});

final class ReturnFailingMover implements MovesBuildingRecords
{
    public static int $order = 25;

    /** @var array<string, list<int>> */
    public static array $movedBefore = [];

    public function order(): int
    {
        return self::$order;
    }

    public function move(BuildingMove $move): void
    {
        self::$movedBefore = $move->moved();

        throw new BuildingMoveFailed('A module could not move its records.');
    }
}

it('rolls the whole return back when any module fails', function (int $order) {
    ReturnFailingMover::$order = $order;
    app(BuildingRecordMovers::class)->register(ReturnFailingMover::class);

    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    $tables = ['properties', 'units', 'leases', 'lease_deposit_entries', 'tenant_profiles', 'owner_profiles', 'agent_building', 'media', 'organization_user', 'model_has_roles', 'invitations'];
    $before = rowsByOrganization($tables);
    $audits = AuditEntry::query()->count();

    expect(fn () => acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(HandoverRefused::class, 'could not be moved, so nothing changed');

    expect(ReturnFailingMover::$movedBefore)->not->toBe([])
        ->and(rowsByOrganization($tables))->toEqual($before)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(storedHandover($handover)->accepted_at)->toBeNull()
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe(['property-owner']);
})->with([
    'after Properties' => [15],
    'after Leasing' => [36],
    'after the files moved' => [95],
]);

it('lets only an agency\'s org-owner offer a return and only the landlord\'s org-owner accept or decline', function () {
    // An administrator of the agency, and a landlord's organization, cannot offer a return.
    expect(fn () => offerReturn($this->tola, $this->agency, $this->landlord, [$this->harbour]))->toThrow(AuthorizationException::class)
        ->and(fn () => offerReturn($this->ngozi, $this->landlord, $this->other, [$this->palm]))->toThrow(AuthorizationException::class)
        ->and(inOrganization($this->agency, fn () => $this->tola->can('offerReturn', ManagementHandover::class)))->toBeFalse()
        ->and(inOrganization($this->agency, fn () => $this->ada->can('offerReturn', ManagementHandover::class)))->toBeTrue()
        ->and(inOrganization($this->landlord, fn () => $this->ngozi->can('offerReturn', ManagementHandover::class)))->toBeFalse();

    // An agency still cannot hand buildings over for management.
    expect(fn () => offerHandover($this->ada, $this->agency, $this->landlord, [$this->harbour]))->toThrow(AuthorizationException::class);

    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);

    // Neither the agency's owner nor the landlord's administrator answers it.
    expect(fn () => inOrganization($this->agency, fn () => app(HandoverService::class)->accept($this->ada, storedHandover($handover), billAfter($handover))))->toThrow(AuthorizationException::class)
        ->and(fn () => acceptHandover($this->emeka, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(AuthorizationException::class)
        ->and(fn () => declineHandover($this->emeka, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(AuthorizationException::class)
        ->and(fn () => withdrawHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(AuthorizationException::class)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);

    // The landlord's owner declines; a new offer is withdrawn by the agency; a third is accepted.
    declineHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Declined)
        ->and(organizationsOf('properties', [$this->harbour->id]))->toBe([$this->harbour->id => $this->agency->id]);

    $withdrawn = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    withdrawHandover($this->ada, $this->agency, $withdrawn);
    expect(storedHandover($withdrawn)->status)->toBe(HandoverStatus::Cancelled);

    $third = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $third));
    expect(storedHandover($third)->status)->toBe(HandoverStatus::Returned);
});

it('expires an unanswered return after 14 days, like any offer', function () {
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    $this->travelTo(now()->addDays(14)->addHour());
    runHandoverJob('handovers-expire');

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Expired)
        ->and(fn () => acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(HandoverRefused::class);
});

it('returns buildings only to an organization run by the account linked to their owner record', function () {
    // Lone Tower's owner record was never linked to an account: the agency invites the owner to the portal first.
    expect(fn () => offerReturn($this->ada, $this->agency, $this->other, [$this->lone]))
        ->toThrow(HandoverRefused::class, 'Bayo Bello, the owner of Lone Tower, has no GoProperty account linked yet. Invite them to the owner portal first');

    // Office Block has no owner record at all.
    expect(fn () => offerReturn($this->ada, $this->agency, $this->landlord, [$this->office]))->toThrow(HandoverRefused::class, 'Office Block has no owner on record');

    // Harbour Point is Ngozi's: it cannot be pushed into Bello Homes, which she does not run.
    expect(fn () => offerReturn($this->ada, $this->agency, $this->other, [$this->harbour]))->toThrow(HandoverRefused::class, 'Bello Homes is not one their owner runs');

    // One owner's buildings at a time.
    linkOwner($this->agency, $this->bayoAtAgency, $this->bayo);
    expect(fn () => offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour, $this->lone]))->toThrow(HandoverRefused::class, 'different owners');

    // An administrator of the landlord's organization is not enough: the linked account must be an owner there.
    $emekaRecord = ownerIn($this->agency, ['type' => 'person', 'first_name' => 'Emeka', 'last_name' => 'Eze', 'email' => 'emeka.eze@example.com', 'phone' => '+2348051113333']);
    linkOwner($this->agency, $emekaRecord, $this->emeka);
    inOrganization($this->agency, fn () => Property::query()->findOrFail($this->garden->id)->forceFill(['owner_profile_id' => $emekaRecord->id])->save());
    expect(fn () => offerReturn($this->ada, $this->agency, $this->landlord, [$this->garden]))->toThrow(HandoverRefused::class, 'is not one their owner runs');

    // Only landlords' organizations take returns, found by web address or an owner's email.
    $targets = app(HandoverTargets::class);
    expect($targets->findLandlord('goproperty.ng/o/eze-properties')?->id)->toBe($this->landlord->id)
        ->and($targets->findLandlord('ngozi.eze@example.com')?->id)->toBe($this->landlord->id)
        ->and($targets->findLandlord('acme-homes'))->toBeNull()
        ->and($targets->find('eze-properties'))->toBeNull()
        ->and(fn () => offerReturn($this->ada, $this->agency, $this->agency, [$this->harbour]))->toThrow(HandoverRefused::class);

    // The safeguard is checked again on acceptance: Ngozi's link removed meanwhile, nothing moves.
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    inOrganization($this->agency, fn () => OwnerProfile::query()->findOrFail($this->ngoziAtAgency->id)->forceFill(['user_id' => null])->save());
    expect(fn () => acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(HandoverRefused::class, 'Invite them to the owner portal first')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(inOrganization($this->agency, fn () => ManagementHandover::query()->count()))->toBe(1);
});

it('cannot offer buildings already in an open handover', function () {
    // Eze hands Palm Court to the agency for management: it is in an open handover there.
    $management = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    acceptHandover($this->ada, $this->agency, incomingHandover($this->agency, $management));
    expect(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->agency->id]);

    expect(fn () => offerReturn($this->ada, $this->agency, $this->landlord, [$this->palm]))->toThrow(HandoverRefused::class, 'Palm Court is already in an open handover')
        ->and(inOrganization($this->agency, fn () => array_values(app(OfferableBuildings::class)->options())))->toBe(['Garden Court', 'Harbour Point', 'Lone Tower', 'Office Block']);

    // A pending return holds its buildings too.
    offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    expect(fn () => offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour, $this->garden]))->toThrow(HandoverRefused::class, 'Harbour Point is already in an open handover');
});

it('offers a return from the handovers page and the landlord takes the buildings back on the accept screen', function () {
    handoversPage($this->ada, 'acme-homes')->assertOk()->assertSee('Return buildings to their landlord')->assertSee('data-test="return-building-'.$this->harbour->id.'"', false);
    handoversPage($this->ngozi, 'eze-properties')->assertOk()->assertDontSee('data-test="return-section"', false);

    $sent = handoversCall($this->ada, 'acme-homes', '', ['returnTarget' => 'goproperty.ng/o/eze-properties', 'returnBuildings' => [(string) $this->harbour->id]], [['offerReturn']])->assertOk();
    expect(LivewireHttp::html($sent))->toContain('You offered to return the buildings to Eze Properties.');
    $handover = inOrganization($this->agency, fn () => ManagementHandover::query()->sole());

    // An unlinked owner's building is refused with the reason.
    $refused = handoversCall($this->ada, 'acme-homes', '', ['returnTarget' => 'eze-properties', 'returnBuildings' => [(string) $this->lone->id]], [['offerReturn']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('Invite them to the owner portal first');

    handoversPage($this->ngozi, 'eze-properties')->assertOk()->assertSee('data-test="kind-return"', false)->assertSee('Adeyemi Realty Ltd');
    handoversPage($this->ngozi, 'eze-properties', "/{$handover->id}")->assertOk()
        ->assertSee('Adeyemi Realty Ltd offers to return its buildings to you')
        ->assertSee('data-test="bill-statement"', false)
        ->assertSee('data-test="return-explainer"', false)
        ->assertSee('Harbour Point')
        ->assertSee('3 units')
        ->assertDontSee('data-test="rent-collection"', false)
        ->assertSee('Accept: take the buildings back');

    $accepted = handoversCall($this->ngozi, 'eze-properties', "/{$handover->id}", [], [['accept']])->assertOk();
    expect(LivewireHttp::html($accepted))->toContain('The buildings are yours again')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Returned);

    foreach ([[$this->ngozi, 'eze-properties'], [$this->ada, 'acme-homes']] as [$user, $slug]) {
        handoversPage($user, $slug, "/{$handover->id}")->assertOk()
            ->assertSee('data-test="returned-text"', false)
            ->assertDontSee('data-test="give-notice-button"', false)
            ->assertDontSee('data-test="withdraw-notice-button"', false);
    }

    // giveNotice through the page is refused too.
    $notice = handoversCall($this->ada, 'acme-homes', "/{$handover->id}", [], [['giveNotice']])->assertOk();
    expect(LivewireHttp::html($notice))->toContain('a return has no notice and no end')
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Returned);
});

it('blocks offering and accepting a return while GoProperty staff impersonate', function (ImpersonationMode $mode, string $side) {
    $index = handoversPage($this->ada, 'acme-homes')->assertOk();
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->garden]);
    $screen = handoversPage($this->ngozi, 'eze-properties', "/{$handover->id}")->assertOk();
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);

    if ($side === 'agency') {
        enterSupport(startSupport($staff, $this->ada, $this->agency, $mode))->assertRedirect('/o/acme-homes');
        asSupport()->get('/o/acme-homes/handovers')->assertForbidden();

        foreach ([['offerReturn'], ['withdraw', [$handover->id]]] as $call) {
            freshRequest();
            LivewireHttp::update(test(), LivewireHttp::snapshot($index), ['returnTarget' => 'eze-properties', 'returnBuildings' => [(string) $this->harbour->id]], [$call])->assertForbidden();
        }
    } else {
        enterSupport(startSupport($staff, $this->ngozi, $this->landlord, $mode))->assertRedirect('/o/eze-properties');
        asSupport()->get("/o/eze-properties/handovers/{$handover->id}")->assertForbidden();

        foreach (['accept', 'decline'] as $call) {
            freshRequest();
            LivewireHttp::update(test(), LivewireHttp::snapshot($screen), [], [[$call]])->assertForbidden();
        }
    }

    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(inOrganization($this->agency, fn () => ManagementHandover::query()->count()))->toBe(1)
        ->and(organizationsOf('properties', [$this->harbour->id, $this->garden->id]))->toBe([$this->harbour->id => $this->agency->id, $this->garden->id => $this->agency->id])
        ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(2);
})->with([
    'read-only' => [ImpersonationMode::ReadOnly],
    'write' => [ImpersonationMode::Write],
])->with(['agency', 'landlord']);

it('keeps a return out of reach of every other organization (cross-organization probes)', function () {
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);

    handoversPage($this->bayo, 'bello-homes', "/{$handover->id}")->assertNotFound();
    expect(incomingHandover($this->other, $handover))->toBeNull()
        ->and(inOrganization($this->other, fn () => ManagementHandover::query()->find($handover->id)))->toBeNull()
        ->and(inOrganization($this->landlord, fn () => ManagementHandover::query()->find($handover->id)))->toBeNull()
        ->and(incomingHandover($this->landlord, $handover)?->kind)->toBe(HandoverKind::Return);

    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));

    // Where the agency's tenants went is the agency's to know: no other organization learns it.
    $moved = fn () => app(MovedTenants::class)->movedOut([$this->tunde->id, $this->kemi->id]);
    expect(array_keys(inOrganization($this->agency, $moved)))->toEqualCanonicalizing([$this->tunde->id, $this->kemi->id])
        ->and(inOrganization($this->landlord, $moved))->toBe([])
        ->and(inOrganization($this->other, $moved))->toBe([])
        ->and(inOrganization($this->other, fn () => app(MovedTenants::class)->movedOutIds()))->toBe([]);
});

it('settles returned tenants\' credit with the roles swapped: the agency chooses, the landlord confirms it arrived', function () {
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);
    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $handover));
    $line = creditLine($handover, $this->tunde->id);
    $credit = liveCredits($handover)[$line->id];
    expect($credit->kobo)->toBe(10_000_000)
        ->and(__($line->settlement->label(storedHandover($handover)->kind)))->toBe('Pass to the landlord');

    // The landlord neither chooses nor records a refund; the agency does not confirm its own payment.
    expect(fn () => inOrganization($this->landlord, fn () => app(HandoverCredits::class)->choose($this->ngozi, incomingHandover($this->landlord, $handover), $line->id, CreditSettlement::Refund)))
        ->toThrow(HandoverRefused::class, 'Only the agency that held the buildings chooses')
        ->and(fn () => inOrganization($this->agency, fn () => app(HandoverCredits::class)->confirmArrived($this->ada, storedHandover($handover), $line->id, $credit)))
        ->toThrow(HandoverRefused::class, 'Only the landlord the buildings returned to confirms');

    // The landlord confirms the agency's payment arrived: closed at the agency, opened on the landlord's record.
    $landlordTunde = inOrganization($this->landlord, fn () => TenantProfile::query()->where('phone', '+2348034445555')->sole());
    inOrganization($this->landlord, fn () => app(HandoverCredits::class)->confirmArrived($this->ngozi, incomingHandover($this->landlord, $handover), $line->id, $credit));

    expect(creditOf($this->agency, $this->tunde)->kobo)->toBe(0)
        ->and(creditOf($this->landlord, $landlordTunde)->kobo)->toBe(10_000_000)
        ->and(creditLine($handover, $this->tunde->id)->settled_by_organization_id)->toBe($this->landlord->id);

    // Nothing is owed either way on the agency's record of Tunde any more: it archived itself, marked "Moved to".
    expect(inOrganization($this->agency, fn () => TenantProfile::query()->withTrashed()->findOrFail($this->tunde->id)->trashed()))->toBeTrue()
        ->and(inOrganization($this->agency, fn () => TenantProfile::query()->findOrFail($this->kemi->id)->trashed()))->toBeFalse();
    tenantsPage($this->ada, "/{$this->tunde->id}")->assertOk()->assertSee('Moved to Eze Properties on 4 January 2027');
});

it('treats an organization role other than owner as unable to answer a return', function (OrganizationRole $role) {
    $member = joinAs(User::factory()->create(['name' => 'Sade Member', 'email' => 'sade.member@example.com', 'phone' => '+2348052223333']), $this->landlord, $role);
    $handover = offerReturn($this->ada, $this->agency, $this->landlord, [$this->harbour]);

    expect(fn () => acceptHandover($member, $this->landlord, incomingHandover($this->landlord, $handover)))->toThrow(AuthorizationException::class)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending);
})->with([OrganizationRole::Admin, OrganizationRole::Agent]);
