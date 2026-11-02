<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Handover;

use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\MovesBuildingRecords;
use App\Tenancy\OrganizationContext;
use Modules\BuildingGroup\Groups\BuildingGroups;

/**
 * The Building Group's part of a building move (P07-01; BILLING_PAYMENTS.md
 * § 7.4): groups do not change organization. The organization the
 * buildings leave keeps its groups, closed, with their members gone (their
 * leases moved); the organization they go to opens its own group for each
 * building (or opens again the one it had, on a return), whose members are
 * the moved tenants' records there holding the moved active leases.
 *
 * Both sides are a BuildingGroups::sync() in that organization's context,
 * so they follow the same rule as every other change. Runs after Leasing
 * (30: the leases and their tenants are already there) and Tickets (50),
 * before the media library (90), inside the move's transaction.
 */
final readonly class BuildingGroupsMover implements MovesBuildingRecords
{
    public function __construct(private OrganizationContext $context, private BuildingGroups $groups) {}

    public function order(): int
    {
        return 60;
    }

    public function move(BuildingMove $move): void
    {
        foreach ($move->propertyIds as $propertyId) {
            $left = $this->context->run($move->from, fn () => $this->groups->sync($propertyId));
            $there = $this->context->run($move->to, fn () => $this->groups->sync($propertyId));

            $move->count('building_group_members_left', count($left->left));
            $move->count('building_group_members_joined', count($there->joined));
        }
    }
}
