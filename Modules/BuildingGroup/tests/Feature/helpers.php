<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\Properties\Models\Property;
use Modules\Tenants\Models\TenantProfile;

/*
 * Helpers for the Building Group's tests (P07-01). Leases are made and
 * ended through the real writer (Leasing's helpers: LeaseService), the
 * nightly sync runs as the scheduler runs it.
 */

require_once __DIR__.'/../../../Leasing/tests/Feature/helpers.php';

/** The building's group in the organization, as stored now (null when it has none). */
function groupOf(Organization $organization, Property|int $building): ?BuildingGroup
{
    $id = $building instanceof Property ? $building->id : $building;

    return inOrganization($organization, fn (): ?BuildingGroup => BuildingGroup::query()->where('property_id', $id)->first());
}

/**
 * The tenants who are members of the building's group now, by tenant
 * record id (ascending); empty when the building has no group.
 *
 * @return list<int>
 */
function membersOf(Organization $organization, Property|int $building): array
{
    $group = groupOf($organization, $building);

    if ($group === null) {
        return [];
    }

    return inOrganization($organization, fn (): array => GroupMember::query()->where('building_group_id', $group->id)->current()
        ->orderBy('tenant_profile_id')->pluck('tenant_profile_id')->map(fn (mixed $id): int => (int) $id)->all());
}

/** The tenant's row in the building's group (current or left), as stored now. */
function memberRow(Organization $organization, Property|int $building, TenantProfile|int $tenant): ?GroupMember
{
    $group = groupOf($organization, $building);
    $tenantId = $tenant instanceof TenantProfile ? $tenant->id : $tenant;

    return $group === null ? null : inOrganization($organization, fn (): ?GroupMember => GroupMember::query()
        ->where('building_group_id', $group->id)->where('tenant_profile_id', $tenantId)->first());
}

/**
 * Every group and member row of every organization, for "nothing changed".
 *
 * @return array{groups: list<array<string, mixed>>, members: list<array<string, mixed>>}
 */
function groupTables(): array
{
    return [
        'groups' => DB::table('building_groups')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'members' => DB::table('group_members')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/** Runs the nightly `building-groups-sync` (02:20 daily) as the scheduler does: the job, queued on `default`, runs inline (sync queue). */
function runGroupSync(): void
{
    app(OrganizationContext::class)->clear();
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === 'building-groups-sync');

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe('20 2 * * *');

    $event->run(app());
}
