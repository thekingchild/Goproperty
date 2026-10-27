<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Documents\DocumentCounter;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Events\LeaseDrafted;
use Modules\Leasing\Events\LeaseEvent;
use Modules\Leasing\Events\LeaseRenewed;
use Modules\Leasing\Leases\LeaseRefused;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Unit;

require_once __DIR__.'/helpers.php';

/*
 * P03-03a: the lease state machine (ARCHITECTURE.md § 6.2–6.3, AGENT.md
 * § 3 rule 5): LeaseService is the only writer; a move LeaseStatus does
 * not allow is refused and changes nothing; a renewal is a new lease
 * linked to the one it renews; the unit's status follows the lease
 * events; contract numbers come from the numberer at activation.
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

/** Everything a refused move must leave as it was. */
function leaseWorld(Organization $organization, Lease $lease, Unit $unit): array
{
    return inOrganization($organization, fn (): array => [
        'lease' => Lease::query()->withTrashed()->findOrFail($lease->id)->getAttributes(),
        'leases' => Lease::query()->withTrashed()->orderBy('id')->get()->map(fn (Lease $row): array => [$row->id, $row->status->value])->all(),
        'unit' => Unit::query()->withTrashed()->findOrFail($unit->id)->status->value,
        'deposits' => LeaseDepositEntry::query()->count(),
        'counters' => DocumentCounter::query()->orderBy('id')->pluck('last_seq')->all(),
        'audit' => AuditEntry::query()->count(),
    ]);
}

/**
 * A lease in the given status on its own unit, made at 1 Jun 2026 (a
 * draft held to 15 Jun; an active lease to 31 Dec 2026); the clock is then
 * moved to 5 Jan 2027, when the drafts' holds and the leases have ended.
 */
function leaseInStatus(object $test, LeaseStatus $status): array
{
    $test->travelTo('2026-06-01 10:00:00');
    $unit = unitIn($test->acme, $test->palm);
    $tenant = tenantIn($test->acme);
    $terms = ['startDate' => '2026-06-01', 'endDate' => '2026-12-31'];
    $service = app(LeaseService::class);

    $lease = match ($status) {
        LeaseStatus::Draft => draftLease($test->acme, $test->owner, $unit, $tenant, $terms),
        LeaseStatus::Active => activeLease($test->acme, $test->owner, $unit, $tenant, $terms),
        LeaseStatus::Terminated => inOrganization($test->acme, fn () => $service->terminate($test->owner, activeLease($test->acme, $test->owner, $unit, $tenant, $terms), 'Moved out early')),
        LeaseStatus::Expired => (function () use ($test, $unit, $tenant, $service): Lease {
            $lease = activeLease($test->acme, $test->owner, $unit, $tenant, ['startDate' => '2025-06-01', 'endDate' => '2026-05-31']);

            return inOrganization($test->acme, fn () => $service->expire($lease));
        })(),
        LeaseStatus::Renewed => (function () use ($test, $unit, $tenant, $terms, $service): Lease {
            $lease = activeLease($test->acme, $test->owner, $unit, $tenant, $terms);
            inOrganization($test->acme, fn () => $service->activate($test->owner, $service->renew($test->owner, $lease, leaseInput(['startDate' => '2027-01-01', 'endDate' => '2027-12-31']))));

            return $lease;
        })(),
    };

    $test->travelTo('2027-01-05 10:00:00');

    return [storedLease($test->acme, $lease), $unit];
}

/** Runs one lease action as the owner. */
function leaseAction(object $test, string $action, Lease $lease): Lease
{
    $service = app(LeaseService::class);

    return inOrganization($test->acme, fn (): Lease => match ($action) {
        'activate' => $service->activate($test->owner, $lease),
        'renew' => $service->renew($test->owner, $lease, leaseInput(['startDate' => '2027-01-01', 'endDate' => '2027-12-31'])),
        'terminate' => $service->terminate($test->owner, $lease, 'Tenant asked to leave'),
        'expire' => $service->expire($lease),
    });
}

const LEASE_MOVES = [
    // status => [action => allowed]
    'draft' => ['activate' => true, 'renew' => false, 'terminate' => true, 'expire' => true],
    'active' => ['activate' => false, 'renew' => true, 'terminate' => true, 'expire' => true],
    'expired' => ['activate' => false, 'renew' => false, 'terminate' => false, 'expire' => false],
    'terminated' => ['activate' => false, 'renew' => false, 'terminate' => false, 'expire' => false],
    'renewed' => ['activate' => false, 'renew' => false, 'terminate' => false, 'expire' => false],
];

dataset('lease moves', function (): array {
    $cases = [];

    foreach (LEASE_MOVES as $status => $actions) {
        foreach ($actions as $action => $allowed) {
            $cases["{$action} a {$status} lease"] = [LeaseStatus::from($status), $action, $allowed];
        }
    }

    return $cases;
});

it('acceptance: refuses every lease move the state machine does not allow, and changes nothing', function (LeaseStatus $status, string $action, bool $allowed) {
    [$lease, $unit] = leaseInStatus($this, $status);
    $before = leaseWorld($this->acme, $lease, $unit);

    if ($allowed) {
        $result = leaseAction($this, $action, $lease);

        $expected = ['activate' => LeaseStatus::Active, 'terminate' => LeaseStatus::Terminated, 'expire' => LeaseStatus::Expired][$action] ?? null;
        $action === 'renew'
            ? expect($result->renewal_of_lease_id)->toBe($lease->id)->and($result->status)->toBe(LeaseStatus::Draft)
            : expect(storedLease($this->acme, $lease)->status)->toBe($expected);

        return;
    }

    try {
        leaseAction($this, $action, $lease);
        $this->fail("{$action} of a {$status->value} lease was allowed.");
    } catch (AuthorizationException|LeaseRefused) {
        // Refused by the policy (the buttons' check) or the service itself.
    }

    expect(leaseWorld($this->acme, $lease, $unit))->toEqual($before);
})->with('lease moves');

it('refuses the moves in the service itself, past any policy, and changes nothing', function () {
    // Every ability allowed: only the service's own state machine stands.
    Gate::before(fn (): bool => true);

    foreach (LEASE_MOVES as $status => $actions) {
        foreach (array_keys(array_filter($actions, fn (bool $allowed): bool => ! $allowed)) as $action) {
            [$lease, $unit] = leaseInStatus($this, LeaseStatus::from($status));
            $before = leaseWorld($this->acme, $lease, $unit);

            expect(fn () => leaseAction($this, $action, $lease))->toThrow(LeaseRefused::class);
            expect(leaseWorld($this->acme, $lease, $unit))->toEqual($before, "{$action} a {$status} lease");
        }
    }
});

it('refuses to expire a lease or a draft before its time', function () {
    $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $other = unitIn($this->acme, $this->palm);
    $active = activeLease($this->acme, $this->owner, $other, tenantIn($this->acme));

    expect(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->expire($draft)))->toThrow(LeaseRefused::class, 'This draft holds its unit until 03/01/2027.')
        ->and(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->expire($active)))->toThrow(LeaseRefused::class, 'This lease runs until 31/12/2027.')
        ->and(storedLease($this->acme, $draft)->status)->toBe(LeaseStatus::Draft)
        ->and(storedLease($this->acme, $active)->status)->toBe(LeaseStatus::Active);
});

it('drafts a lease that reserves the vacant unit for 14 days, audited, with no contract number yet', function () {
    $lease = draftLease($this->acme, $this->owner, $this->flat, $this->kemi, ['notes' => 'Keys with the caretaker.']);

    expect($lease->status)->toBe(LeaseStatus::Draft)
        ->and($lease->property_id)->toBe($this->palm->id)
        ->and($lease->unit_id)->toBe($this->flat->id)
        ->and($lease->tenant_profile_id)->toBe($this->kemi->id)
        ->and($lease->contract_number)->toBeNull()
        ->and($lease->next_billing_date)->toBeNull()
        ->and($lease->reserved_until?->toDateString())->toBe('2027-01-03')
        ->and($lease->rent_amount_kobo->kobo)->toBe(150_000_000)
        ->and($lease->deposit_kobo->kobo)->toBe(15_000_000)
        ->and($lease->created_by_user_id)->toBe($this->owner->id)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved)
        ->and(depositEntriesOf($this->acme, $lease))->toBe([]);

    $audit = AuditEntry::query()->where('event', 'lease.drafted')->sole();
    expect($audit->log_name)->toBe('leases')
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->subject_id)->toBe($lease->id)
        ->and(AuditEntry::query()->where('event', 'unit.status_changed')->sole()->properties['reason'])->toBe("lease_drafted:{$lease->id}");
});

it('holds the unit for as many days as the organization says', function () {
    inOrganization($this->acme, fn () => app(SettingsStore::class)->set(LeaseService::RESERVATION_DAYS_SETTING, 3));

    expect(draftLease($this->acme, $this->owner, $this->flat, $this->kemi)->reserved_until?->toDateString())->toBe('2026-12-23');
});

it('activates a draft: contract number, first billing date, deposit taken, unit occupied', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);

    expect($lease->status)->toBe(LeaseStatus::Active)
        ->and($lease->contract_number)->toBe('LS-ADR-2026-000001')
        ->and($lease->next_billing_date?->toDateString())->toBe('2027-01-01')
        ->and($lease->reserved_until)->toBeNull()
        ->and($lease->activated_at?->toDateTimeString())->toBe('2026-12-20 10:00:00')
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied)
        ->and(depositEntriesOf($this->acme, $lease))->toBe([['held', 15_000_000]])
        ->and(AuditEntry::query()->where('event', 'lease.activated')->sole()->properties['contract_number'])->toBe('LS-ADR-2026-000001');
});

it('starts billing with the first period after the day the rent is paid until', function () {
    $monthly = activeLease($this->acme, $this->owner, $this->flat, $this->kemi, ['billingCycle' => 'monthly', 'startDate' => '2027-01-31', 'endDate' => '2028-01-30', 'rent' => '125,000', 'paidUntil' => '2027-03-30']);
    $yearly = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme), ['paidUntil' => '2027-12-31']);

    // 31 Jan monthly: 31 Jan, 28 Feb, 31 Mar… (CycleCalculator: back to the anchor day).
    expect($monthly->next_billing_date?->toDateString())->toBe('2027-03-31')
        // Paid for the whole year: nothing left to bill.
        ->and($yearly->next_billing_date?->toDateString())->toBe('2028-01-01')
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($yearly->id)))->toBeNull()
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($monthly->id)?->nextBillingDate->toDateString()))->toBe('2027-03-31');
});

it('acceptance: links a renewal to the lease it renews, and carries the deposit over on activation', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $events = [];
    Event::listen([LeaseDrafted::class, LeaseActivated::class, LeaseRenewed::class], function (LeaseEvent $event) use (&$events): void {
        $events[] = [class_basename($event), $event->leaseId, $event->unitStatus];
    });

    $renewal = inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $lease, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31', 'rent' => '1,650,000'])));

    expect($renewal->renewal_of_lease_id)->toBe($lease->id)
        ->and($renewal->status)->toBe(LeaseStatus::Draft)
        ->and($renewal->tenant_profile_id)->toBe($this->kemi->id)
        ->and($renewal->unit_id)->toBe($this->flat->id)
        ->and($renewal->rent_amount_kobo->kobo)->toBe(165_000_000)
        // The deposit held is carried, not typed again.
        ->and($renewal->deposit_kobo->kobo)->toBe(15_000_000)
        ->and(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Active)
        ->and(inOrganization($this->acme, fn () => storedLease($this->acme, $renewal)->renewalOf?->id))->toBe($lease->id)
        ->and(inOrganization($this->acme, fn () => storedLease($this->acme, $lease)->renewals()->pluck('id')->all()))->toBe([$renewal->id])
        // Still let to the tenant: the waiting renewal reserves nothing.
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied);

    $active = inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $renewal));
    $previous = storedLease($this->acme, $lease);

    expect($previous->status)->toBe(LeaseStatus::Renewed)
        ->and($active->status)->toBe(LeaseStatus::Active)
        ->and($active->contract_number)->toBe('LS-ADR-2026-000002')
        ->and($active->next_billing_date?->toDateString())->toBe('2028-01-01')
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Occupied)
        ->and(depositEntriesOf($this->acme, $previous))->toBe([['held', 15_000_000], ['carried_out', -15_000_000]])
        ->and(depositEntriesOf($this->acme, $active))->toBe([['carried_in', 15_000_000]])
        ->and(AuditEntry::query()->where('event', 'lease.renewed')->sole()->subject_id)->toBe($lease->id)
        ->and($events)->toBe([
            ['LeaseDrafted', $renewal->id, null],
            ['LeaseRenewed', $lease->id, null],
            ['LeaseActivated', $renewal->id, null],
        ])
        // The renewed lease still bills to its own end date.
        ->and(inOrganization($this->acme, fn () => app(LeaseBillingSchedule::class)->find($lease->id)?->status))->toBe('renewed');

    // The pages show the link both ways.
    leasesPage($this->owner, "/{$renewal->id}")->assertOk()->assertSee('data-test="renewal-of"', false)->assertSee('LS-ADR-2026-000001');
    leasesPage($this->owner, "/{$lease->id}")->assertOk()->assertSee('data-test="renewed-by"', false)->assertSee('LS-ADR-2026-000002');
});

it('starts a renewal after the lease it renews, and allows one renewal at a time', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $renew = fn (array $terms) => inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $lease, leaseInput($terms)));

    expect(fn () => $renew(['startDate' => '2027-12-31', 'endDate' => '2028-12-31']))
        ->toThrow(ValidationException::class, 'A renewal starts after the lease it renews ends (31/12/2027).');

    $renewal = $renew(['startDate' => '2028-01-01', 'endDate' => '2028-12-31']);

    expect(fn () => $renew(['startDate' => '2028-01-01', 'endDate' => '2028-12-31']))
        ->toThrow(LeaseRefused::class, 'This lease already has a renewal.');

    // Cancelling the renewal allows another one.
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $renewal, 'Tenant changed their mind'));
    expect($renew(['startDate' => '2028-01-01', 'endDate' => '2028-06-30'])->renewal_of_lease_id)->toBe($lease->id);
});

it('keeps the unit status in step with every lease event', function () {
    $service = app(LeaseService::class);
    $act = fn (Closure $step) => inOrganization($this->acme, $step);
    $status = fn (): UnitStatus => unitStatusOf($this->acme, $this->flat);

    $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    expect($status())->toBe(UnitStatus::Reserved);

    // A second draft waits for the same unit; cancelling one keeps it reserved for the other.
    $waiting = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme));
    $act(fn () => $service->terminate($this->owner, $draft, 'Changed their mind'));
    expect($status())->toBe(UnitStatus::Reserved);

    $act(fn () => $service->activate($this->owner, $waiting));
    expect($status())->toBe(UnitStatus::Occupied);

    // The next tenant's draft waits while the unit is let.
    $next = draftLease($this->acme, $this->owner, $this->flat, $this->kemi, ['startDate' => '2028-01-01', 'endDate' => '2028-12-31']);
    expect($status())->toBe(UnitStatus::Occupied);

    // Ending the lease: the unit is reserved for the waiting draft.
    $act(fn () => $service->terminate($this->owner, storedLease($this->acme, $waiting), 'Moved out'));
    expect($status())->toBe(UnitStatus::Reserved);

    $act(fn () => $service->terminate($this->owner, $next, 'Found another flat'));
    expect($status())->toBe(UnitStatus::Vacant);

    // Every change went through the units writer, audited with the event.
    expect(AuditEntry::query()->where('event', 'unit.status_changed')->orderBy('id')->get()->map(fn (AuditEntry $entry): array => [$entry->properties['status_to'], strtok($entry->properties['reason'], ':')])->all())
        ->toBe([['reserved', 'lease_drafted'], ['occupied', 'lease_activated'], ['reserved', 'lease_terminated'], ['vacant', 'lease_terminated']]);
});

it('changes the unit and the lease together or not at all', function () {
    $draft = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    Event::listen(LeaseActivated::class, fn () => throw new RuntimeException('A listener failed.'));

    expect(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $draft)))->toThrow(RuntimeException::class, 'A listener failed.');

    expect(storedLease($this->acme, $draft)->status)->toBe(LeaseStatus::Draft)
        ->and(storedLease($this->acme, $draft)->contract_number)->toBeNull()
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved)
        ->and(depositEntriesOf($this->acme, $draft))->toBe([])
        ->and(AuditEntry::query()->where('event', 'lease.activated')->exists())->toBeFalse();
});

it('gives contract numbers per organization without gaps, refused and failed activations included', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $otherOwner = joinAs(User::factory()->create(), $other);
    $otherBuilding = buildingIn($other);
    $numbers = [];

    foreach (range(1, 3) as $n) {
        $numbers['acme'][] = activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme))->contract_number;

        if ($n < 3) {
            $numbers['other'][] = activeLease($other, $otherOwner, unitIn($other, $otherBuilding), tenantIn($other))->contract_number;
        }

        // A refused activation (the unit is let already) takes no number.
        $blocked = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme));
        if ($n === 1) {
            activeLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme));
            $numbers['acme'][] = 'flat-1';
        }
        expect(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $blocked)))->toThrow(LeaseRefused::class);
    }

    expect($numbers['acme'])->toBe(['LS-ADR-2026-000001', 'flat-1', 'LS-ADR-2026-000003', 'LS-ADR-2026-000004'])
        ->and(leasesOf($this->acme)->first(fn (Lease $lease): bool => $lease->unit_id === $this->flat->id && $lease->status === LeaseStatus::Active)?->contract_number)->toBe('LS-ADR-2026-000002')
        ->and($numbers['other'])->toBe(['LS-BEH-2026-000001', 'LS-BEH-2026-000002'])
        ->and(inOrganization($this->acme, fn () => DocumentCounter::query()->sole()->last_seq))->toBe(4);
});

it('records what became of the deposit once the lease has ended, and keeps the entries as they are', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $settle = fn (array $input) => inOrganization($this->acme, fn () => app(LeaseService::class)->settleDeposit($this->owner, storedLease($this->acme, $lease), $input));

    // Not while the lease runs (the policy refuses it first).
    expect(fn () => $settle(['refunded' => '150,000']))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $lease, 'Moved out'));

    expect(fn () => $settle(['refunded' => '100,000', 'kept' => '60,000', 'note' => 'Broken door']))->toThrow(LeaseRefused::class, 'Only ₦150,000 of the deposit is held.')
        ->and(fn () => $settle(['kept' => '50,000']))->toThrow(ValidationException::class, 'Say why part of the deposit is kept.')
        ->and(fn () => $settle([]))->toThrow(LeaseRefused::class, 'Enter the amount refunded, kept, or both.');

    $settle(['refunded' => '100,000', 'kept' => '50,000', 'note' => 'Broken door']);

    expect(depositEntriesOf($this->acme, $lease))->toBe([['held', 15_000_000], ['refunded', -10_000_000], ['kept', -5_000_000]])
        ->and(inOrganization($this->acme, fn () => app(LeaseService::class)->depositBalance($lease)->kobo))->toBe(0)
        ->and(AuditEntry::query()->where('event', 'lease.deposit_settled')->sole()->properties['refunded_kobo'])->toBe(10_000_000)
        ->and(fn () => $settle(['refunded' => '1']))->toThrow(LeaseRefused::class);

    // A ledger: entries are never changed or deleted.
    $entry = inOrganization($this->acme, fn () => LeaseDepositEntry::query()->firstOrFail());
    expect(fn () => inOrganization($this->acme, fn () => $entry->forceFill(['note' => 'changed'])->save()))->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $entry->delete()))->toThrow(LogicException::class);
});

it('changes a draft\'s terms, audited, and nothing once it is active', function () {
    $lease = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $update = fn (array $terms) => inOrganization($this->acme, fn () => app(LeaseService::class)->update($this->owner, storedLease($this->acme, $lease), leaseInput($terms)));

    $update(['rent' => '1,600,000', 'dueDayOffset' => '5']);
    $update(['rent' => '1,600,000', 'dueDayOffset' => '5']);

    expect(storedLease($this->acme, $lease)->rent_amount_kobo->kobo)->toBe(160_000_000)
        ->and(AuditEntry::query()->where('event', 'lease.updated')->sole()->properties['changed'])->toBe(['rent_amount_kobo', 'due_day_offset']);

    inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, storedLease($this->acme, $lease)));
    expect(fn () => $update(['rent' => '1,700,000']))->toThrow(AuthorizationException::class)
        ->and(storedLease($this->acme, $lease)->rent_amount_kobo->kobo)->toBe(160_000_000);
});

it('checks the terms on the server', function (array $terms, string $field, string $message) {
    expect(fn () => draftLease($this->acme, $this->owner, $this->flat, $this->kemi, $terms))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()[$field][0] ?? null)->toBe($message));

    expect(leasesOf($this->acme))->toHaveCount(0)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Vacant);
})->with([
    'end before start' => [['endDate' => '2026-12-31'], 'endDate', 'The lease must end after it starts.'],
    'no rent' => [['rent' => '0'], 'rent', 'Enter an amount above ₦0.'],
    'rent not an amount' => [['rent' => 'a lot'], 'rent', 'Enter an amount in naira, such as 1,500,000.'],
    'unknown cycle' => [['billingCycle' => 'fortnightly'], 'billingCycle', 'The selected billing cycle is invalid.'],
    'daily due offset' => [['billingCycle' => 'daily', 'dueDayOffset' => '1'], 'dueDayOffset', 'A daily lease is due on its day: the offset must be 0.'],
    'weekly due offset' => [['billingCycle' => 'weekly', 'dueDayOffset' => '7'], 'dueDayOffset', 'The bill must fall due within the period: at most 6 days after it starts.'],
    'paid until after the end' => [['paidUntil' => '2028-01-01'], 'paidUntil', 'Paid until must be a day between the start and the end of the lease.'],
    'not a date' => [['startDate' => '31/12/2026'], 'startDate', 'Enter a date.'],
]);

it('leases only a unit that can be let, to a tenant who is not archived', function () {
    $broken = unitIn($this->acme, $this->palm, ['status' => UnitStatus::UnderMaintenance]);
    $archivedTenant = tenantIn($this->acme);
    inOrganization($this->acme, fn () => $archivedTenant->delete());
    $closed = buildingIn($this->acme, ['status' => PropertyStatus::Archived]);

    expect(fn () => draftLease($this->acme, $this->owner, $broken, $this->kemi))->toThrow(ValidationException::class, 'is under maintenance. Make it vacant before leasing it.')
        ->and(fn () => draftLease($this->acme, $this->owner, $this->flat, $archivedTenant))->toThrow(ValidationException::class, 'This tenant is archived.')
        ->and(fn () => draftLease($this->acme, $this->owner, unitIn($this->acme, $closed), $this->kemi))->toThrow(ValidationException::class, 'is archived. Restore it before letting its units.')
        ->and(leasesOf($this->acme))->toHaveCount(0);
});

it('makes no new leases while the organization is restricted, but still ends them', function () {
    $active = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme));
    $this->acme->forceFill(['status' => OrganizationStatus::Restricted])->save();
    $service = app(LeaseService::class);

    expect(fn () => draftLease($this->acme, $this->owner, unitIn($this->acme, $this->palm), tenantIn($this->acme)))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $service->activate($this->owner, $draft)))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $service->renew($this->owner, $active, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31']))))->toThrow(AuthorizationException::class);

    inOrganization($this->acme, fn () => $service->terminate($this->owner, $active, 'Moved out'));
    expect(storedLease($this->acme, $active)->status)->toBe(LeaseStatus::Terminated);
});
