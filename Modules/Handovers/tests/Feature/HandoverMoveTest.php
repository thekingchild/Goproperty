<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MovesBuildingRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityType;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Models\AgentAssignment;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P03-14 AC (IMPLEMENTATION_PLAN.md § 3): accepting moves exactly the
 * chosen buildings with their units, tenants, active leases and files into
 * the agency's organization in one transaction, and the agency's billable
 * units rise by that count; issued bills, receipts and payments stay with
 * their issuer; a failure in any module rolls back the whole move; ending
 * with notice moves them back on the end date (time travel through the
 * hourly job). BILLING_PAYMENTS.md § 7.4 for what moves and what stays.
 */

beforeEach(fn () => handoverWorld());

afterEach(fn () => handoversFreshRequest());

/** The history tables, whose rows never move. */
const HANDOVER_HISTORY_TABLES = ['bills', 'bill_items', 'payments', 'payment_allocations', 'receipts', 'tenant_credit_entries', 'tenant_credit_balances', 'lease_contracts', 'document_verifications', 'document_counters', 'expenses', 'expense_receipts'];

/** How many audit entries there are. */
function AuditEntryCount(): int
{
    return AuditEntry::query()->count();
}

/**
 * The properties of the audit entries of a log and event, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function activity_entries(string $log, string $event): array
{
    return AuditEntry::query()->where('log_name', $log)->where('event', $event)->orderBy('id')->get()
        ->map(fn (AuditEntry $entry): array => $entry->properties?->toArray() ?? [])->all();
}

it('moves exactly the chosen buildings with their units, tenants, live leases, deposits, utilities and files into the agency in one transaction', function () {
    $billableBefore = inOrganization($this->agency, fn (): int => app(UnitDirectory::class)->countBillable());
    $history = rowsByOrganization(HANDOVER_HISTORY_TABLES);
    $issuedFiles = inOrganization($this->landlord, fn () => Media::query()->whereNotIn('collection_name', ['photos', 'meter_photos'])->orderBy('id')->pluck('id')->all());

    $nextBilling = inOrganization($this->landlord, fn () => Lease::query()->findOrFail($this->tundeLease->id)->next_billing_date?->toDateString());
    expect($nextBilling)->not->toBeNull();

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    expect(billAfter($handover))->toEqual(Money::naira(12_800));

    acceptHandover($this->ada, $this->agency, $handover);

    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Active)
        ->and($stored->billable_units)->toBe(5)
        ->and($stored->monthly_before_kobo)->toEqual(Money::naira(10_000))
        ->and($stored->monthly_after_kobo)->toEqual(Money::naira(12_800))
        ->and($stored->plan_key)->toBe('starter');

    // The agency's bill counts exactly the 5 live units more (Flat 4 is archived).
    expect(inOrganization($this->agency, fn (): int => app(UnitDirectory::class)->countBillable()))->toBe($billableBefore + 5)
        ->and(inOrganization($this->landlord, fn (): int => app(UnitDirectory::class)->countBillable()))->toBe(1);

    // Buildings and units (the archived one too) are the agency's; Cedar House stays.
    expect(organizationsOf('properties', [$this->palm->id, $this->lagoon->id, $this->cedar->id]))
        ->toBe([$this->palm->id => $this->agency->id, $this->lagoon->id => $this->agency->id, $this->cedar->id => $this->landlord->id])
        ->and(array_unique(organizationsOf('units', [$this->flat1->id, $this->flat2->id, $this->flat3->id, $this->flat4->id, $this->suite1->id, $this->suite2->id])))->toBe([$this->flat1->id => $this->agency->id])
        ->and(organizationsOf('units', [$this->unitA->id]))->toBe([$this->unitA->id => $this->landlord->id]);

    // Live leases (active and the draft) move, with their deposits; Femi's stays.
    $moved = [$this->tundeLease->id, $this->kemiLease->id, $this->musaLease->id, $this->bisiDraft->id];
    expect(array_unique(organizationsOf('leases', $moved)))->toBe([$this->tundeLease->id => $this->agency->id])
        ->and(organizationsOf('leases', [$this->femiLease->id]))->toBe([$this->femiLease->id => $this->landlord->id]);

    $deposits = inOrganization($this->agency, fn () => LeaseDepositEntry::query()->orderBy('lease_id')->get(['lease_id', 'amount_kobo']));
    expect($deposits->pluck('lease_id')->all())->toBe([$this->tundeLease->id, $this->kemiLease->id])
        ->and($deposits->pluck('amount_kobo')->map->kobo->all())->toBe([15_000_000, 12_000_000]);

    // Each moving tenant has a record at the agency (Kemi's existing one is matched); the landlord keeps its own, with their history.
    $agencyTenants = inOrganization($this->agency, fn () => TenantProfile::query()->orderBy('phone')->get());
    expect($agencyTenants->pluck('phone')->all())->toBe(['+2348031112222', '+2348034445555', '+2348036667777', '+2348037778888'])
        ->and($agencyTenants->firstWhere('phone', '+2348031112222')->id)->toBe($this->agencyKemi->id);

    $leases = inOrganization($this->agency, fn () => Lease::query()->orderBy('id')->get()->keyBy('id'));
    expect($leases[$this->kemiLease->id]->tenant_profile_id)->toBe($this->agencyKemi->id)
        ->and($leases[$this->tundeLease->id]->tenant_profile_id)->toBe($agencyTenants->firstWhere('phone', '+2348034445555')->id)
        ->and($leases[$this->tundeLease->id]->status)->toBe(LeaseStatus::Active)
        ->and($leases[$this->bisiDraft->id]->status)->toBe(LeaseStatus::Draft)
        ->and($leases[$this->tundeLease->id]->next_billing_date?->toDateString())->toBe($nextBilling);
    expect(inOrganization($this->landlord, fn () => TenantProfile::query()->count()))->toBe(5);

    // Utility settings point at the agency's types (Water matched by code, the security levy made there); readings and the meter photo follow.
    $settings = inOrganization($this->agency, fn () => UnitUtilitySetting::query()->with('utilityType')->orderBy('id')->get());
    expect($settings->modelKeys())->toBe([$this->water->id, $this->security->id])
        ->and($settings[0]->utility_type_id)->toBe($this->agencyWater->id)
        ->and($settings[1]->utilityType->code)->toBe('SECURITY')
        ->and(inOrganization($this->agency, fn () => UtilityType::query()->orderBy('code')->pluck('code')->all()))->toBe(['SECURITY', 'WATER'])
        ->and(inOrganization($this->landlord, fn () => UtilityType::query()->count()))->toBe(2)
        ->and(array_unique(organizationsOf('utility_readings', [$this->firstReading->id, $this->photoReading->id])))->toBe([$this->firstReading->id => $this->agency->id]);

    // History stayed exactly where it was.
    expect(rowsByOrganization(HANDOVER_HISTORY_TABLES))->toEqual($history)
        ->and(inOrganization($this->landlord, fn () => Media::query()->whereNotIn('collection_name', ['photos', 'meter_photos'])->orderBy('id')->pluck('id')->all()))->toBe($issuedFiles);
});

it('keeps bills, receipts, payments, credit and expenses with the organization that issued them (counts per organization before and after)', function () {
    $before = rowsByOrganization(HANDOVER_HISTORY_TABLES);
    expect($before['bills'])->toBe([$this->landlord->id => 5])
        ->and($before['payments'])->toBe([$this->landlord->id => 1])
        ->and($before['receipts'])->toBe([$this->landlord->id => 1])
        ->and($before['expenses'])->toBe([$this->landlord->id => 1]);

    acceptHandover($this->ada, $this->agency, offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]));

    expect(rowsByOrganization(HANDOVER_HISTORY_TABLES))->toEqual($before);
});

it('moves the photo and meter photo files to the agency\'s folder: readable there, gone from the old folder', function () {
    $files = array_map(fn (Media $media): string => $media->uuid, [...$this->photos, ...inOrganization($this->landlord, fn () => Media::query()->where('collection_name', 'meter_photos')->get()->all())]);
    expect($files)->toHaveCount(3);

    foreach ($files as $uuid) {
        expect(Storage::disk('media')->allFiles("{$this->landlord->id}/{$uuid}"))->not->toBeEmpty();
    }

    $oldLinks = inOrganization($this->landlord, fn (): array => array_map(fn (Media $media): string => app(MediaUrls::class)->temporary($media), Media::query()->whereIn('uuid', $files)->get()->all()));

    acceptHandover($this->ada, $this->agency, offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]));

    foreach ($files as $uuid) {
        expect(Storage::disk('media')->allFiles("{$this->landlord->id}/{$uuid}"))->toBe([])
            ->and(Storage::disk('media')->allFiles("{$this->agency->id}/{$uuid}"))->not->toBeEmpty();
    }

    $moved = inOrganization($this->agency, fn () => Media::query()->whereIn('uuid', $files)->orderBy('id')->get());
    expect($moved)->toHaveCount(3)
        ->and(inOrganization($this->landlord, fn () => Media::query()->whereIn('uuid', $files)->count()))->toBe(0);

    foreach ($moved as $media) {
        handoversFreshRequest();
        $link = inOrganization($this->agency, fn (): string => app(MediaUrls::class)->temporary($media));
        $response = $this->get($link)->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk('media')->get("{$this->agency->id}/{$media->uuid}/{$media->file_name}"));
    }

    // The building page still shows its cover photo, now from the agency.
    expect(inOrganization($this->agency, fn () => Property::query()->findOrFail($this->palm->id)->coverPhoto?->uuid))->toBe($this->photos[0]->uuid);

    foreach ($oldLinks as $link) {
        handoversFreshRequest();
        $this->get($link)->assertNotFound();
    }
});

it('gives a moved building the next free web address and clears import references taken there, and drops its agent assignments', function () {
    // The agency already has a Palm Court (palm-court, B-PALM) and a unit U-1; and a lease with Tunde's contract number.
    $number = inOrganization($this->landlord, fn () => Lease::query()->findOrFail($this->tundeLease->id)->contract_number);
    expect($number)->not->toBeNull();
    $agencyFlat = inOrganization($this->agency, fn () => Unit::query()->where('unit_label', 'Flat 9')->sole());
    $agencyLease = draftLease($this->agency, $this->ada, $agencyFlat, $this->agencyKemi, ['rent' => '700,000', 'deposit' => '0']);
    inOrganization($this->agency, fn () => Lease::query()->whereKey($agencyLease->id)->update(['contract_number' => $number]));
    expect(inOrganization($this->landlord, fn () => AgentAssignment::query()->where('property_id', $this->palm->id)->count()))->toBe(1);

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);

    $buildings = inOrganization($this->agency, fn () => Property::query()->whereKey([$this->palm->id, $this->lagoon->id, $this->agencyPalm->id])->orderBy('id')->get()->keyBy('id'));
    expect($buildings[$this->palm->id]->slug)->toBe('palm-court-2')
        ->and($buildings[$this->palm->id]->external_ref)->toBeNull()
        ->and($buildings[$this->palm->id]->import_batch_id)->toBeNull()
        ->and($buildings[$this->lagoon->id]->slug)->toBe('lagoon-view')
        ->and($buildings[$this->agencyPalm->id]->slug)->toBe('palm-court')
        ->and($buildings[$this->agencyPalm->id]->external_ref)->toBe('B-PALM');

    expect(inOrganization($this->agency, fn () => Unit::query()->withTrashed()->whereKey([$this->flat1->id])->value('external_ref')))->toBeNull()
        ->and(inOrganization($this->agency, fn () => Unit::query()->whereKey($agencyFlat->id)->value('external_ref')))->toBe('U-1')
        ->and(inOrganization($this->agency, fn () => Lease::query()->whereKey($this->tundeLease->id)->value('contract_number')))->toBeNull()
        ->and(inOrganization($this->agency, fn () => Lease::query()->whereKey($agencyLease->id)->value('contract_number')))->toBe($number);

    // The landlord's caretaker loses the building; the agency's members are assigned by the agency.
    expect(DB::table('agent_building')->where('property_id', $this->palm->id)->count())->toBe(0);
    buildingsPage($this->caretaker, '/palm-court', 'eze-properties')->assertNotFound();
    buildingsPage($this->ada, '/palm-court-2', 'acme-homes')->assertOk()->assertSee('Palm Court')->assertSee('Flat 1');

    $moved = activity_entries('handovers', 'handover.records_moved_in');
    expect($moved)->toHaveCount(1)
        ->and($moved[0]['counts'])->toMatchArray(['agent_assignments_dropped' => 1, 'slugs_changed' => 1, 'contract_numbers_cleared' => 1, 'owner_profiles_created' => 1, 'tenants_matched' => 1, 'tenants_copied' => 3, 'utility_types_created' => 1, 'files_moved' => 3]);
});

it('makes the landlord the buildings\' owner at the agency, linked to their account, so they follow them in /owner', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);

    $owner = inOrganization($this->agency, fn () => OwnerProfile::query()->where('user_id', $this->ngozi->id)->sole());
    expect($owner->display_name)->toBe('Eze Properties')
        ->and($owner->email)->toBe('ngozi.eze@example.com')
        ->and(storedHandover($handover)->owner_profile_id)->toBe($owner->id)
        ->and(inOrganization($this->agency, fn () => Property::query()->whereKey([$this->palm->id, $this->lagoon->id])->pluck('owner_profile_id')->unique()->all()))->toBe([$owner->id])
        ->and($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe(['property-owner'])
        ->and(storedHandover($handover)->buildings->pluck('previous_owner_profile_id', 'property_id')->all())->toEqual([$this->lagoon->id => null, $this->palm->id => $this->ngoziRecord->id]);

    $page = ownerPortalPage($this->ngozi)->assertOk()->assertSee('Palm Court')->assertSee('Lagoon View')->assertDontSee('Office Block')->assertDontSee('Cedar House');
    expect(ownerPortalIds($page, 'building'))->toBe([$this->palm->id, $this->lagoon->id]);
    ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}")->assertOk()->assertSee('Flat 1')->assertSee('Tunde Bakare');

    // Ngozi still works in her own workspace, never the agency's.
    $this->actingAs($this->ngozi)->get('/o/acme-homes/buildings')->assertNotFound();
});

it('matches an owner record the agency already has for the landlord, by its link or its email', function () {
    $existing = ownerIn($this->agency, ['type' => 'person', 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi.eze@example.com', 'phone' => '+2348051112222']);

    acceptHandover($this->ada, $this->agency, offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]));

    expect(inOrganization($this->agency, fn () => OwnerProfile::query()->count()))->toBe(1)
        ->and(inOrganization($this->agency, fn () => OwnerProfile::query()->findOrFail($existing->id)->user_id))->toBe($this->ngozi->id)
        ->and(inOrganization($this->agency, fn () => Property::query()->findOrFail($this->lagoon->id)->owner_profile_id))->toBe($existing->id);
});

final class HandoverFailingMover implements MovesBuildingRecords
{
    public static int $order = 25;

    public static bool $plain = false;

    /** @var array<string, list<int>> */
    public static array $movedBefore = [];

    public function order(): int
    {
        return self::$order;
    }

    public function move(BuildingMove $move): void
    {
        self::$movedBefore = $move->moved();

        throw self::$plain ? new RuntimeException('The database went away.') : new BuildingMoveFailed('A module could not move its records.');
    }
}

it('rolls the whole move back when any module fails, files included', function (int $order, bool $plain) {
    HandoverFailingMover::$order = $order;
    HandoverFailingMover::$plain = $plain;
    app(BuildingRecordMovers::class)->register(HandoverFailingMover::class);

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    $tables = ['properties', 'units', 'leases', 'lease_deposit_entries', 'tenant_profiles', 'unit_utility_settings', 'utility_readings', 'utility_types', 'owner_profiles', 'agent_building', 'media', 'organization_user', 'model_has_roles'];
    $before = rowsByOrganization($tables);
    $files = Storage::disk('media')->allFiles();
    sort($files);
    $audits = AuditEntryCount();

    expect(fn () => acceptHandover($this->ada, $this->agency, $handover))->toThrow($plain ? RuntimeException::class : HandoverRefused::class);

    // Something had moved before the failure (except when the first module failed)...
    expect(HandoverFailingMover::$movedBefore === [])->toBe($order < 10);

    // ...and nothing stayed moved.
    expect(rowsByOrganization($tables))->toEqual($before)
        ->and(storedHandover($handover)->status)->toBe(HandoverStatus::Pending)
        ->and(storedHandover($handover)->accepted_at)->toBeNull()
        ->and(collect(Storage::disk('media')->allFiles())->sort()->values()->all())->toBe($files)
        ->and(Storage::disk('media')->allFiles((string) $this->agency->id))->toBe([])
        ->and(AuditEntryCount())->toBe($audits)
        ->and(inOrganization($this->landlord, fn () => Property::query()->findOrFail($this->palm->id)->slug))->toBe('palm-court')
        ->and($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe([]);

    foreach ($this->photos as $photo) {
        expect(Storage::disk('media')->allFiles("{$this->landlord->id}/{$photo->uuid}"))->not->toBeEmpty();
    }
})->with([
    'before Properties' => [5, false],
    'after Properties' => [15, false],
    'after Tenants' => [25, false],
    'after Leasing' => [35, false],
    'after Billing' => [45, false],
    'after the files moved' => [95, false],
    'an unexpected error after the files moved' => [95, true],
]);

it('ends a management with notice: on the end date the hourly job moves the live records back, and what the agency issued stays with it', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon], noticeDays: 30);
    acceptHandover($this->ada, $this->agency, $handover);
    $agencyTunde = inOrganization($this->agency, fn () => TenantProfile::query()->where('phone', '+2348034445555')->sole());

    // While it manages them, the agency bills the moved units with its own numbers: the security levy for February.
    $this->travelTo('2027-01-26 09:00:00');
    runBillGeneration();
    $agencyBills = inOrganization($this->agency, fn () => Bill::query()->orderBy('id')->get());
    expect($agencyBills)->toHaveCount(1)
        ->and($agencyBills[0]->tenant_profile_id)->toBe($this->agencyKemi->id)
        ->and($agencyBills[0]->number)->toStartWith('INV-ADR');

    $history = rowsByOrganization(HANDOVER_HISTORY_TABLES);

    // Ngozi gives notice on 1 February: management ends 30 days later, 3 March.
    $this->travelTo('2027-02-01 10:00:00');
    giveHandoverNotice($this->ngozi, $this->landlord, $handover);
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ending)
        ->and(storedHandover($handover)->ends_at?->toIso8601String())->toBe('2027-03-03T10:00:00+00:00');

    // The day before, and an hour before, the hourly job leaves it alone.
    foreach (['2027-03-02 10:25:00', '2027-03-03 09:25:00'] as $moment) {
        $this->travelTo($moment);
        runHandoverJob('handovers-end');
        expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ending)
            ->and(organizationsOf('properties', [$this->palm->id]))->toBe([$this->palm->id => $this->agency->id]);
    }

    $this->travelTo('2027-03-03 10:25:00');
    runHandoverJob('handovers-end');

    $stored = storedHandover($handover);
    expect($stored->status)->toBe(HandoverStatus::Ended)
        ->and($stored->ended_at?->toIso8601String())->toBe('2027-03-03T10:25:00+00:00');

    // The live records are the landlord's again; Palm Court gets its owner record and web address back.
    expect(array_unique(organizationsOf('properties', [$this->palm->id, $this->lagoon->id])))->toBe([$this->palm->id => $this->landlord->id])
        ->and(array_unique(organizationsOf('units', [$this->flat1->id, $this->flat2->id, $this->flat3->id, $this->flat4->id, $this->suite1->id, $this->suite2->id])))->toBe([$this->flat1->id => $this->landlord->id])
        ->and(array_unique(organizationsOf('leases', [$this->tundeLease->id, $this->kemiLease->id, $this->musaLease->id, $this->bisiDraft->id])))->toBe([$this->tundeLease->id => $this->landlord->id])
        ->and(array_unique(organizationsOf('unit_utility_settings', [$this->water->id, $this->security->id])))->toBe([$this->water->id => $this->landlord->id])
        ->and(array_unique(organizationsOf('utility_readings', [$this->firstReading->id, $this->photoReading->id])))->toBe([$this->firstReading->id => $this->landlord->id]);

    $palm = inOrganization($this->landlord, fn () => Property::query()->findOrFail($this->palm->id));
    expect($palm->slug)->toBe('palm-court')
        ->and($palm->owner_profile_id)->toBe($this->ngoziRecord->id)
        ->and(inOrganization($this->landlord, fn () => Lease::query()->whereKey([$this->tundeLease->id, $this->kemiLease->id])->orderBy('id')->pluck('tenant_profile_id')->all()))->toBe([$this->tunde->id, $this->kemi->id])
        ->and(inOrganization($this->landlord, fn () => UnitUtilitySetting::query()->orderBy('id')->pluck('utility_type_id')->all()))->toBe(inOrganization($this->landlord, fn () => UtilityType::query()->orderBy('code')->pluck('id')->reverse()->values()->all()))
        ->and(inOrganization($this->landlord, fn () => app(UnitDirectory::class)->countBillable()))->toBe(6);

    // The photos came back too.
    foreach ($this->photos as $photo) {
        expect(Storage::disk('media')->allFiles("{$this->landlord->id}/{$photo->uuid}"))->not->toBeEmpty()
            ->and(Storage::disk('media')->allFiles("{$this->agency->id}/{$photo->uuid}"))->toBe([]);
    }

    // History stays where it was written: the agency keeps its bill (and tenant records), the landlord its own.
    expect(rowsByOrganization(HANDOVER_HISTORY_TABLES))->toEqual($history)
        ->and(inOrganization($this->agency, fn () => TenantProfile::query()->whereKey($agencyTunde->id)->exists()))->toBeTrue();

    // Ngozi owns nothing more at the agency: her link there is removed, and /owner is empty.
    expect($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe([])
        ->and(inOrganization($this->agency, fn () => OwnerProfile::query()->whereNotNull('user_id')->count()))->toBe(0);
    expect(ownerPortalIds(ownerPortalPage($this->ngozi), 'building'))->toBe([]);

    // Running the job again changes nothing.
    runHandoverJob('handovers-end');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ended);
});

it('keeps the landlord linked at the agency when it still owns other buildings there after a return', function () {
    $first = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    acceptHandover($this->ada, $this->agency, $first);
    $second = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    acceptHandover($this->ada, $this->agency, $second);

    giveHandoverNotice($this->ada, $this->agency, $first);
    $this->travelTo(now()->addDays(31));
    runHandoverJob('handovers-end');

    expect(storedHandover($first)->status)->toBe(HandoverStatus::Ended)
        ->and(storedHandover($second)->status)->toBe(HandoverStatus::Active)
        ->and($this->ngozi->fresh()->roleNamesIn($this->agency))->toBe(['property-owner'])
        ->and(ownerPortalIds(ownerPortalPage($this->ngozi), 'building'))->toBe([$this->lagoon->id]);
});
