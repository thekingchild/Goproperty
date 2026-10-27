<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Events\LeaseEndingSoon;
use Modules\Leasing\Events\LeaseExpired;
use Modules\Leasing\Leases\LeaseService;
use Modules\Properties\Enums\UnitStatus;

require_once __DIR__.'/helpers.php';

/*
 * P03-03a: the daily `leases:expire-and-flag` job (ARCHITECTURE.md § 7;
 * P03 decision 5), run through the scheduler: stale reservations are
 * released, ended leases expire, leases ending soon are flagged once; per
 * organization, idempotent.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo']);
});

afterEach(fn () => leasesFreshRequest());

/** Runs the scheduled `leases-expire-and-flag` event, as the scheduler does. */
function runLeaseSchedule(): void
{
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'leases-expire-and-flag');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('0 2 * * *');

    $event->run(app());
}

it('releases a stale reservation through the scheduled job, the day after the draft\'s hold ends', function () {
    $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $expired = [];
    Event::listen(LeaseExpired::class, function (LeaseExpired $event) use (&$expired): void {
        $expired[] = [$event->leaseId, $event->wasDraft, $event->unitStatus];
    });

    expect($draft->reserved_until?->toDateString())->toBe('2027-01-03');

    // Still held on its last day (Lagos: 3 Jan, 02:00 + 1 h).
    $this->travelTo('2027-01-03 01:00:00');
    runLeaseSchedule();
    expect(storedLease($this->acme, $draft)->status)->toBe(LeaseStatus::Draft)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved);

    $this->travelTo('2027-01-04 01:00:00');
    runLeaseSchedule();

    $stored = storedLease($this->acme, $draft);
    expect($stored->status)->toBe(LeaseStatus::Expired)
        ->and($stored->expired_at?->toDateTimeString())->toBe('2027-01-04 01:00:00')
        ->and($stored->contract_number)->toBeNull()
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Vacant)
        ->and($expired)->toBe([[$draft->id, true, 'vacant']]);

    $audit = AuditEntry::query()->where('event', 'lease.reservation_released')->sole();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->properties['reserved_until'])->toBe('2027-01-03');

    // Running it again changes nothing.
    $entries = AuditEntry::query()->count();
    runLeaseSchedule();
    expect(AuditEntry::query()->count())->toBe($entries)
        ->and($expired)->toHaveCount(1);
});

it('keeps the unit reserved for a draft still waiting when another draft\'s hold ends', function () {
    $first = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $this->travelTo('2026-12-30 10:00:00');
    $second = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme));

    $this->travelTo('2027-01-04 10:00:00');
    runLeaseSchedule();

    expect(storedLease($this->acme, $first)->status)->toBe(LeaseStatus::Expired)
        ->and(storedLease($this->acme, $second)->status)->toBe(LeaseStatus::Draft)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved);

    // A draft past its hold but not yet expired by the job can still be activated if the unit is free.
    $this->travelTo('2027-01-14 10:00:00');
    $active = inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $second));
    expect($active->status)->toBe(LeaseStatus::Active)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied);
});

it('expires leases after their end date in Lagos, and releases the unit', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $renewing = activeLease($this->acme, $this->owner, $other = unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $renewal = inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $renewing, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31'])));
    $waiting = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme), ['startDate' => '2028-01-01', 'endDate' => '2028-12-31']);

    // 31 Dec 2027, 23:30 in Lagos: the last day of the leases.
    $this->travelTo('2027-12-31 22:30:00');
    runLeaseSchedule();
    expect(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Active)
        // The drafts' holds ended long ago (3 Jan 2027): released, the leases still let the units.
        ->and(storedLease($this->acme, $waiting)->status)->toBe(LeaseStatus::Expired)
        ->and(storedLease($this->acme, $renewal)->status)->toBe(LeaseStatus::Expired)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied);

    // 1 Jan 2028, 00:30 in Lagos.
    $this->travelTo('2027-12-31 23:30:00');
    runLeaseSchedule();

    expect(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Expired)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Vacant)
        ->and(storedLease($this->acme, $renewing)->status)->toBe(LeaseStatus::Expired)
        ->and(unitStatusOf($this->acme, $other))->toBe(UnitStatus::Vacant)
        ->and(AuditEntry::query()->where('event', 'lease.expired')->count())->toBe(2);
});

it('leaves an auto-renewing lease active past its end date and flags it instead', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi, ['autoRenew' => true]);

    $this->travelTo('2028-01-02 10:00:00');
    runLeaseSchedule();

    $stored = storedLease($this->acme, $lease);
    expect($stored->status)->toBe(LeaseStatus::Active)
        ->and($stored->ending_soon_flagged_at)->not->toBeNull()
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied);
});

it('flags a lease ending within the notice window once, unless it has a renewal', function () {
    $soon = activeLease($this->acme, $this->owner, $this->flat, $this->kemi, ['startDate' => '2026-02-16', 'endDate' => '2027-02-15']);
    $renewed = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['startDate' => '2026-02-16', 'endDate' => '2027-02-15']);
    inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $renewed, leaseInput(['startDate' => '2027-02-16', 'endDate' => '2028-02-15'])));
    $later = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $flagged = [];
    Event::listen(LeaseEndingSoon::class, function (LeaseEndingSoon $event) use (&$flagged): void {
        $flagged[] = [$event->leaseId, $event->endDate->toDateString(), $event->unitStatus];
    });

    runLeaseSchedule();
    runLeaseSchedule();

    expect($flagged)->toBe([[$soon->id, '2027-02-15', null]])
        ->and(storedLease($this->acme, $soon)->ending_soon_flagged_at?->toDateTimeString())->toBe('2026-12-20 10:00:00')
        ->and(storedLease($this->acme, $renewed)->ending_soon_flagged_at)->toBeNull()
        ->and(storedLease($this->acme, $later)->ending_soon_flagged_at)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'lease.ending_soon')->sole()->subject_id)->toBe($soon->id);

    leasesPage($this->owner, '?ending=1')->assertOk()->assertSee('data-test="ending-'.$soon->id.'"', false);
});

it('uses each organization\'s notice window', function () {
    inOrganization($this->acme, fn () => app(SettingsStore::class)->set(LeaseService::ENDING_SOON_DAYS_SETTING, 30));
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi, ['startDate' => '2026-02-16', 'endDate' => '2027-02-15']);

    runLeaseSchedule();
    expect(storedLease($this->acme, $lease)->ending_soon_flagged_at)->toBeNull();

    $this->travelTo('2027-01-16 10:00:00');
    runLeaseSchedule();
    expect(storedLease($this->acme, $lease)->ending_soon_flagged_at)->not->toBeNull();
});

it('works organization by organization, suspended ones too, and never across them', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $otherUnit = unitIn($other, buildingIn($other));
    $acmeDraft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $otherDraft = draftLease($other, $otherOwner, $otherUnit, tenantIn($other));
    $other->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $this->travelTo('2027-01-04 10:00:00');
    runLeaseSchedule();

    expect(storedLease($this->acme, $acmeDraft)->status)->toBe(LeaseStatus::Expired)
        ->and(storedLease($other, $otherDraft)->status)->toBe(LeaseStatus::Expired)
        ->and(unitStatusOf($other, $otherUnit))->toBe(UnitStatus::Vacant)
        ->and(AuditEntry::query()->where('event', 'lease.reservation_released')->pluck('subject_id')->sort()->values()->all())->toBe([$acmeDraft->id, $otherDraft->id]);
});
