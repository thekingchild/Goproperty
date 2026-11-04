<?php

declare(strict_types=1);

require_once __DIR__.'/report-world.php';
require_once __DIR__.'/../../../Handovers/tests/Feature/helpers.php';

/*
 * P08-04: a management handover moves buildings between two organizations
 * by query (no model events), so the move itself tells both organizations'
 * dashboards (OrganizationMoves). On the handover world
 * (Handovers/tests/Feature/handover-world.php): Eze Properties hands Palm
 * Court and Lagoon View to Adeyemi Realty.
 */

beforeEach(fn () => handoverWorld());

afterEach(fn () => exportsFreshRequest());

/** The names of the buildings in the organization's occupancy report, in a fresh request. */
function occupancyBuildings($organization, $user): array
{
    exportsFreshRequest();

    return array_map(fn ($building) => $building->name, reportOf($organization, $user)->occupancy->buildings);
}

it('acceptance: a management handover shows on both organizations\' next load', function () {
    expect(occupancyBuildings($this->landlord, $this->ngozi))->toBe(['Cedar House', 'Lagoon View', 'Palm Court'])
        ->and(occupancyBuildings($this->agency, $this->ada))->toBe(['Office Block', 'Palm Court']);

    acceptHandover($this->ada, $this->agency, offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]));

    expect(occupancyBuildings($this->landlord, $this->ngozi))->toBe(['Cedar House'])
        ->and(occupancyBuildings($this->agency, $this->ada))->toBe(['Lagoon View', 'Office Block', 'Palm Court', 'Palm Court']);
});
