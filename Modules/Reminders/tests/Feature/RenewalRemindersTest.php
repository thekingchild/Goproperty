<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\ReminderType;
use Modules\Reminders\Enums\SkipReason;
use Modules\Reminders\Jobs\DispatchReminders;
use Modules\Reminders\Models\Reminder;

require_once __DIR__.'/reminder-world.php';

/*
 * P05-01: lease renewal alerts to staff at T−60, T−30 and T−7 before the
 * lease's last day (ARCHITECTURE.md § 6.7; docs/plans/P05-subtasks.md
 * defaults 10 and 11). The days around each rung are lived too, so the
 * alerts are proved to go on their exact days only. The world's leases
 * end on 31 December 2027: rungs on 1 November, 1 December and
 * 24 December 2027.
 */

beforeEach(function () {
    reminderWorld();
});

/** Lives each rung's day and the days either side of it. */
function liveRenewalRungs(): array
{
    foreach (['2027-10-31', '2027-11-01', '2027-11-02', '2027-11-30', '2027-12-01', '2027-12-02', '2027-12-23', '2027-12-24', '2027-12-25'] as $day) {
        liveReminderDay($day);
    }

    return array_map(
        fn (array $lines): array => array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'lease_renewal'))),
        array_filter(sentReminders(test()->acme), fn (array $lines): bool => array_filter($lines, fn (string $line): bool => str_starts_with($line, 'lease_renewal')) !== []),
    );
}

it('alerts the agent assigned to the building on the exact rung days, each once, in the app and by email', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

    $bola = fn (string $rung): array => ['lease_renewal '.$rung.' email '.RM_BOLA, 'lease_renewal '.$rung.' in_app '.RM_BOLA];
    expect(liveRenewalRungs())->toBe([
        '2027-11-01' => $bola('T-60'),
        '2027-12-01' => $bola('T-30'),
        '2027-12-24' => $bola('T-7'),
    ]);

    $mail = collect(reminderMails())->first(fn (array $mail): bool => $mail[0] === RM_BOLA);
    $lease = storedLease($this->acme, leasesOf($this->acme)->sole());
    expect($mail[1])->toBe('Lease ends in 60 days, on 31 December 2027: Flat 3B, Palm Court')
        ->and($mail[2])->toContain('The lease of Kemi Adebayo for Flat 3B, Palm Court ('.$lease->contract_number.') ends in 60 days, on 31 December 2027.')
        ->and($mail[2])->toContain('No renewal has been drafted yet.')
        ->and($mail[2])->toContain(route('workspace.leases.show', ['organization' => 'acme-homes', 'lease' => $lease->id]));
});

it('alerts the owners and administrators when no agent is assigned to the building, never an agent of no building', function () {
    activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi, ['autoRenew' => true]);

    foreach (['2027-11-01', '2027-11-02'] as $day) {
        liveReminderDay($day);
    }

    $renewals = remindersOf($this->acme)->where('type', ReminderType::LeaseRenewal);
    expect($renewals->pluck('recipient_email')->unique()->sort()->values()->all())->toBe([RM_ADA, RM_SEYI])
        ->and($renewals->every(fn (Reminder $r): bool => $r->status === ReminderStatus::Sent))->toBeTrue();

    $mail = collect(reminderMails())->first(fn (array $mail): bool => $mail[0] === RM_ADA);
    expect($mail[2])->toContain('It is set to renew automatically, so it rolls on by itself unless you change it.');
});

it('stops once a renewal is drafted, also between planning and sending', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    liveReminderDay('2027-11-01');

    // 1 December: planned at 07:35, renewal drafted at 08:00, not sent at 09:35.
    $this->travelTo('2027-12-01 06:35:00');
    runReminders();
    $this->travelTo('2027-12-01 07:00:00');
    inOrganization($this->acme, fn (): Lease => app(LeaseService::class)->renew($this->owner, storedLease($this->acme, $lease), leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31'])));
    $this->travelTo('2027-12-01 08:35:00');
    runReminders();
    liveReminderDay('2027-12-24');

    $renewals = remindersOf($this->acme)->where('type', ReminderType::LeaseRenewal);
    expect($renewals->where('offset_days', -60)->pluck('status')->unique()->all())->toBe([ReminderStatus::Sent])
        ->and($renewals->where('offset_days', -30)->map(fn (Reminder $r) => $r->skip_reason)->unique()->values()->all())->toBe([SkipReason::RenewalDrafted])
        ->and($renewals->where('offset_days', -7))->toBeEmpty();
});

it('sends no renewal alerts while the organization is suspended, but still reminds its tenants of their bills', function () {
    activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $sent = liveReminderDays('2026-12-02', '2026-12-02');
    foreach (['2027-11-01', '2027-12-01'] as $day) {
        liveReminderDay($day);
    }

    expect($sent)->toBe(['2026-12-02' => ['rent_due T-30 email '.RM_KEMI, 'rent_due T-30 in_app '.RM_KEMI]])
        ->and(remindersOf($this->acme)->where('type', ReminderType::LeaseRenewal))->toBeEmpty();
});

it('is scheduled hourly at :35 on the notifications queue', function () {
    remindersScheduledEvent();

    expect((new DispatchReminders)->queue)->toBe('notifications')
        ->and(config('hosting.queue_tick.lanes.notifications.queues'))->toContain('notifications');
});
