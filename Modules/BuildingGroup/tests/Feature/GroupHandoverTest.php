<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../Handovers/tests/Feature/helpers.php';

/*
 * P07-01 with a management handover (BILLING_PAYMENTS.md § 7.4;
 * Handover\BuildingGroupsMover): a moved building's leases move, so its
 * tenants leave the group of the organization the building leaves (which
 * keeps the group, closed) and join the receiving organization's own group
 * for it, as their tenant records there; a return opens the landlord's
 * group again on the same rows. World: the handover world
 * (Modules/Handovers/tests/Feature/handover-world.php): Palm Court (Tunde,
 * Kemi) and Lagoon View (Musa; Bisi only a draft) move from Eze
 * Properties to Adeyemi Realty, Cedar House (Femi) stays.
 */

beforeEach(fn () => handoverWorld());

afterEach(fn () => handoversFreshRequest());

/** The agency's tenant record for this landlord tenant (by phone). */
function agencyRecordOf(TenantProfile $tenant): int
{
    return inOrganization(test()->agency, fn (): int => TenantProfile::query()->where('phone', $tenant->phone)->firstOrFail()->id);
}

it('moves the members with the leases: the landlord\'s groups close, the agency\'s open with the moved tenants, and a return opens the landlord\'s again', function () {
    expect(membersOf($this->landlord, $this->palm))->toBe([$this->tunde->id, $this->kemi->id])
        ->and(membersOf($this->landlord, $this->lagoon))->toBe([$this->musa->id])
        ->and(membersOf($this->landlord, $this->cedar))->toBe([$this->femi->id]);

    $palmGroup = groupOf($this->landlord, $this->palm);
    $rows = [memberRow($this->landlord, $this->palm, $this->tunde)->id, memberRow($this->landlord, $this->palm, $this->kemi)->id];

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon], noticeDays: 30);
    $this->travelTo('2027-01-04 09:00:00');
    acceptHandover($this->ada, $this->agency, $handover);

    // Eze Properties keeps its groups, closed, everyone gone.
    expect(groupOf($this->landlord, $this->palm)->id)->toBe($palmGroup->id)
        ->and(groupOf($this->landlord, $this->palm)->is_active)->toBeFalse()
        ->and(groupOf($this->landlord, $this->palm)->closed_at?->toDateTimeString())->toBe('2027-01-04 09:00:00')
        ->and(membersOf($this->landlord, $this->palm))->toBe([])
        ->and(memberRow($this->landlord, $this->palm, $this->tunde)->left_at?->toDateTimeString())->toBe('2027-01-04 09:00:00')
        ->and(groupOf($this->landlord, $this->lagoon)->is_active)->toBeFalse()
        ->and(membersOf($this->landlord, $this->lagoon))->toBe([])
        // Cedar House stays as it was.
        ->and(groupOf($this->landlord, $this->cedar)->is_active)->toBeTrue()
        ->and(membersOf($this->landlord, $this->cedar))->toBe([$this->femi->id]);

    // Adeyemi Realty has its own groups, with the moved tenants' records there (Kemi's existing one); Bisi's draft adds nobody.
    $agencyPalm = groupOf($this->agency, $this->palm);
    expect($agencyPalm)->not->toBeNull()
        ->and($agencyPalm->id)->not->toBe($palmGroup->id)
        ->and($agencyPalm->organization_id)->toBe($this->agency->id)
        ->and($agencyPalm->is_active)->toBeTrue()
        ->and(membersOf($this->agency, $this->palm))->toBe(collect([agencyRecordOf($this->tunde), $this->agencyKemi->id])->sort()->values()->all())
        ->and(membersOf($this->agency, $this->lagoon))->toBe([agencyRecordOf($this->musa)])
        // Its own Palm Court (another building) is untouched.
        ->and(groupOf($this->agency, $this->agencyPalm))->toBeNull();

    // Recorded with the move.
    $movedIn = AuditEntry::query()->where('event', 'handover.records_moved_in')->latest('id')->firstOrFail();
    expect($movedIn->properties['counts'])->toMatchArray(['building_group_members_left' => 3, 'building_group_members_joined' => 3]);

    // Ngozi gives notice; 30 days later the hourly job moves everything back.
    $this->travelTo('2027-02-01 10:00:00');
    giveHandoverNotice($this->ngozi, $this->landlord, $handover);
    $this->travelTo('2027-03-03 10:25:00');
    runHandoverJob('handovers-end');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ended);

    // The landlord's own group opens again, the same tenants on the same rows.
    expect(groupOf($this->landlord, $this->palm)->id)->toBe($palmGroup->id)
        ->and(groupOf($this->landlord, $this->palm)->is_active)->toBeTrue()
        ->and(groupOf($this->landlord, $this->palm)->closed_at)->toBeNull()
        ->and(membersOf($this->landlord, $this->palm))->toBe([$this->tunde->id, $this->kemi->id])
        ->and([memberRow($this->landlord, $this->palm, $this->tunde)->id, memberRow($this->landlord, $this->palm, $this->kemi)->id])->toBe($rows)
        ->and(memberRow($this->landlord, $this->palm, $this->kemi)->joined_at?->toDateTimeString())->toBe('2027-03-03 10:25:00')
        ->and(membersOf($this->landlord, $this->lagoon))->toBe([$this->musa->id])
        // The agency's groups close.
        ->and(groupOf($this->agency, $this->palm)->is_active)->toBeFalse()
        ->and(membersOf($this->agency, $this->palm))->toBe([])
        ->and(membersOf($this->agency, $this->lagoon))->toBe([]);
});
