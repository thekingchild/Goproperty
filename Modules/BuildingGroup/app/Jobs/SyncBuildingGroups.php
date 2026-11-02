<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\BuildingGroup\Groups\BuildingGroups;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * `building-groups:sync` (daily 02:20, after `leases:expire-and-flag`;
 * P07-01, docs/plans/P07-subtasks.md default 6): every organization's
 * buildings and groups are synced (BuildingGroups::sync), so a group
 * exists for every active building, a closed building's group is closed,
 * and each group's members match the active leases — the backfill for the
 * leases that existed before P07-01, and a safety net for anything an
 * event missed. The lease events keep members in step during the day.
 *
 * Works inside each organization's context (no scope removed). Safe to run
 * again: a sync of a building already in step changes nothing. Stops after
 * BUDGET_SECONDS and queues itself again from where it stopped
 * (organization, then building), so a shared host's worker never runs it
 * past ~50 s (AGENT.md § 4).
 */
final class SyncBuildingGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int BUDGET_SECONDS = 40;

    public int $timeout = 50;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $fromOrganizationId = 0,
        public readonly int $fromPropertyId = 0,
        public readonly int $budgetSeconds = self::BUDGET_SECONDS,
    ) {
        $this->onQueue('default');
    }

    public function handle(OrganizationContext $context, BuildingGroups $groups, PropertyDirectory $properties): void
    {
        $started = microtime(true);
        $organizations = Organization::query()->where('id', '>=', $this->fromOrganizationId)->orderBy('id')->pluck('id');

        foreach ($organizations as $organizationId) {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                continue;
            }

            $after = $organization->id === $this->fromOrganizationId ? $this->fromPropertyId : 0;

            $stoppedAt = $context->run($organization, function () use ($groups, $properties, $after, $started): ?int {
                foreach ($this->buildings($properties, $after) as $propertyId) {
                    if (microtime(true) - $started > $this->budgetSeconds) {
                        return $propertyId;
                    }

                    $groups->sync($propertyId);
                }

                return null;
            });

            if ($stoppedAt !== null) {
                // Out of time: the next run starts at this building.
                self::dispatch($organization->id, $stoppedAt, $this->budgetSeconds);

                return;
            }
        }
    }

    /**
     * The organization's active buildings and the buildings of its groups,
     * from $from on, ascending.
     *
     * @return list<int>
     */
    private function buildings(PropertyDirectory $properties, int $from): array
    {
        $ids = array_map('intval', array_keys($properties->activeOptions()));
        $grouped = BuildingGroup::query()->pluck('property_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $ids = array_values(array_filter(array_unique([...$ids, ...$grouped]), static fn (int $id): bool => $id >= $from));
        sort($ids);

        return $ids;
    }
}
