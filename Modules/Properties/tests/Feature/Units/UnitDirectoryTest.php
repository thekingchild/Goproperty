<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Properties\Contracts\UnitCounts;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitSummary;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Enums\UnitType;
use Modules\Properties\Models\Unit;

require_once __DIR__.'/helpers.php';

/*
 * P02-02: Modules\Properties\Contracts\UnitDirectory, how other modules read
 * units and set the status a lease gives them (ARCHITECTURE.md § 6.2, § 8
 * rule 3): counts, the billable count (BILLING_PAYMENTS.md § 1.4), setStatus
 * and the row lock Leasing uses; the current organization's units only.
 */

beforeEach(function () {
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

it('finds the current organization\'s live units only', function () {
    $flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1', 'unit_type' => UnitType::SelfContain, 'status' => UnitStatus::Occupied, 'base_rent_kobo' => Money::naira(900_000), 'service_charge_kobo' => Money::zero()]);
    $archived = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']);
    inOrganization($this->acme, fn () => $archived->delete());
    $foreign = unitIn($this->other, buildingIn($this->other), ['unit_label' => 'Flat 1']);

    inOrganization($this->acme, function () use ($flat, $archived, $foreign): void {
        $directory = app(UnitDirectory::class);
        $summary = $directory->find($flat->id);

        expect($summary)->toBeInstanceOf(UnitSummary::class)
            ->and($summary->label)->toBe('Flat 1')
            ->and($summary->propertyId)->toBe($this->palm->id)
            ->and($summary->propertyName)->toBe('Palm Court')
            ->and($summary->propertyArchived)->toBeFalse()
            ->and($summary->organizationId)->toBe($this->acme->id)
            ->and($summary->type)->toBe('self_contain')
            ->and($summary->typeLabel)->toBe('Self-contain')
            ->and($summary->status)->toBe(UnitDirectory::OCCUPIED)
            ->and($summary->statusLabel)->toBe('Occupied')
            ->and($summary->baseRent->kobo)->toBe(90_000_000)
            ->and($summary->serviceCharge->isZero())->toBeTrue()
            ->and($directory->find($archived->id))->toBeNull()
            ->and($directory->find($foreign->id))->toBeNull()
            ->and(array_keys($directory->findMany([$flat->id, $archived->id, $foreign->id, 999999])))->toBe([$flat->id])
            ->and($directory->findMany([]))->toBe([]);
    });
});

it('counts units by status per building and for the organization, and counts the billable units as BILLING_PAYMENTS.md § 1.4 says', function () {
    $cedar = buildingIn($this->acme, ['name' => 'Cedar Gardens']);
    $old = buildingIn($this->acme, ['name' => 'Old Yard']);
    foreach ([UnitStatus::Vacant, UnitStatus::Occupied, UnitStatus::Occupied, UnitStatus::Reserved, UnitStatus::UnderMaintenance, UnitStatus::Unavailable] as $status) {
        unitIn($this->acme, $this->palm, ['status' => $status]);
    }
    unitIn($this->acme, $cedar, ['status' => UnitStatus::Occupied]);
    unitIn($this->acme, $old, ['status' => UnitStatus::Vacant]);
    $archived = unitIn($this->acme, $cedar, ['status' => UnitStatus::Vacant]);
    inOrganization($this->acme, fn () => $archived->delete());
    inOrganization($this->acme, fn () => $old->forceFill(['status' => PropertyStatus::Archived])->save());
    unitIn($this->other, buildingIn($this->other), ['status' => UnitStatus::Occupied]);

    inOrganization($this->acme, function () use ($cedar, $old): void {
        $directory = app(UnitDirectory::class);
        $palm = $directory->countsForProperty($this->palm->id);

        expect($palm)->toBeInstanceOf(UnitCounts::class)
            ->and($palm->byStatus())->toBe(['vacant' => 1, 'reserved' => 1, 'occupied' => 2, 'under_maintenance' => 1, 'unavailable' => 1])
            ->and($palm->total())->toBe(6)
            ->and($palm->occupancyPercent())->toBe(33)
            ->and($directory->countsForProperty($cedar->id)->total())->toBe(1)
            // An archived building's units are still its units, but not billed.
            ->and($directory->countsForProperty($old->id)->total())->toBe(1)
            ->and($directory->countsForProperty(999999)->total())->toBe(0)
            ->and($directory->countsForProperty(999999)->occupancyPercent())->toBe(0)
            ->and($directory->counts()->total())->toBe(7)
            ->and($directory->counts()->occupied)->toBe(3)
            // Every unit not archived in a building not archived, whatever its status (unavailable too).
            ->and($directory->countBillable())->toBe(7);
    });

    expect(inOrganization($this->other, fn () => app(UnitDirectory::class)->countBillable()))->toBe(1);
});

it('sets the status a lease gives a unit, audited with the reason, and refuses unknown statuses and units', function () {
    $flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $foreign = unitIn($this->other, buildingIn($this->other));

    inOrganization($this->acme, function () use ($flat, $foreign): void {
        $directory = app(UnitDirectory::class);

        expect($directory->setStatus($flat->id, UnitDirectory::RESERVED, 'Draft lease LSE-2026-0001')->status)->toBe('reserved')
            ->and($directory->setStatus($flat->id, UnitDirectory::OCCUPIED, 'Lease LSE-2026-0001 activated')->status)->toBe('occupied')
            // The same status again changes nothing and writes no entry.
            ->and($directory->setStatus($flat->id, UnitDirectory::OCCUPIED, 'Again')->status)->toBe('occupied')
            ->and(fn () => $directory->setStatus($flat->id, 'archived', 'No'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $directory->setStatus($foreign->id, UnitDirectory::OCCUPIED, 'No'))->toThrow(ModelNotFoundException::class);
    });

    $entries = AuditEntry::query()->where('log_name', 'properties')->where('event', 'unit.status_changed')->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[1]->subject_id)->toBe($flat->id)
        ->and($entries[1]->properties['status_from'])->toBe('reserved')
        ->and($entries[1]->properties['status_to'])->toBe('occupied')
        ->and($entries[1]->properties['reason'])->toBe('Lease LSE-2026-0001 activated')
        ->and(inOrganization($this->other, fn () => Unit::query()->find($foreign->id)->status))->toBe(UnitStatus::Vacant);
});

it('locks a unit row for Leasing inside a transaction only', function () {
    $flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']);
    $foreign = unitIn($this->other, buildingIn($this->other));

    inOrganization($this->acme, function () use ($flat, $foreign): void {
        $directory = app(UnitDirectory::class);

        DB::transaction(function () use ($directory, $flat, $foreign): void {
            $locked = $directory->lock($flat->id);

            expect($locked?->id)->toBe($flat->id)
                ->and($locked?->status)->toBe(UnitDirectory::VACANT)
                ->and($directory->lock($foreign->id))->toBeNull();
        });

        // RefreshDatabase runs each test inside a transaction: step out of
        // it (last, as it drops this test's rows) to see that a lock outside
        // any transaction is refused before anything is read.
        $level = DB::transactionLevel();
        DB::rollBack(0);

        try {
            expect(DB::transactionLevel())->toBe(0)
                ->and(fn () => $directory->lock($flat->id))->toThrow(LogicException::class, 'Lock a unit inside a database transaction.');
        } finally {
            while (DB::transactionLevel() < $level) {
                DB::beginTransaction();
            }
        }
    });
});

it('takes the lock with SELECT … FOR UPDATE', function () {
    $flat = unitIn($this->acme, $this->palm);
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    inOrganization($this->acme, fn () => DB::transaction(fn () => app(UnitDirectory::class)->lock($flat->id)));

    expect(collect($sql)->contains(fn (string $query): bool => str_contains($query, 'from `units`') && str_ends_with($query, 'for update')))->toBeTrue();
});

it('refuses to answer without an organization', function () {
    unitIn($this->acme, $this->palm);

    expect(fn () => app(UnitDirectory::class)->countBillable())->toThrow(MissingOrganizationContext::class)
        ->and(fn () => app(UnitDirectory::class)->find(1))->toThrow(MissingOrganizationContext::class);
});
