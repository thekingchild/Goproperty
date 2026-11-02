<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Providers;

use App\Tenancy\Moves\BuildingRecordMovers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\BuildingGroup\Handover\BuildingGroupsMover;
use Modules\BuildingGroup\Jobs\SyncBuildingGroups;
use Modules\BuildingGroup\Listeners\FollowLeases;
use Modules\BuildingGroup\Listeners\OpenBuildingGroup;
use Modules\Properties\Events\PropertyCreated;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The BuildingGroup module (ARCHITECTURE.md § 6.8; P07): one discussion
 * group per building. P07-01: the groups and their members, kept in step
 * with leases — the Leasing module's lease events (Listeners\FollowLeases,
 * inside the lease's transaction), the Properties module's PropertyCreated
 * (Listeners\OpenBuildingGroup), the building move of a management
 * handover (Handover\BuildingGroupsMover) and the daily
 * `building-groups:sync` (Jobs\SyncBuildingGroups, Schedule::job). It
 * reads the other modules only through their contracts (ARCHITECTURE.md
 * § 8 rule 3).
 */
final class BuildingGroupServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'BuildingGroup';

    protected string $nameLower = 'buildinggroup';

    public function boot(): void
    {
        parent::boot();

        foreach (FollowLeases::events() as $event) {
            Event::listen($event, FollowLeases::class);
        }

        Event::listen(PropertyCreated::class, OpenBuildingGroup::class);

        // A management handover: the leaving organization's group closes, the receiving one's opens (BILLING_PAYMENTS.md § 7.4).
        $this->callAfterResolving(BuildingRecordMovers::class, static fn (BuildingRecordMovers $movers) => $movers->register(BuildingGroupsMover::class));

        // The backfill and safety net, after leases:expire-and-flag (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new SyncBuildingGroups, 'default')->name('building-groups-sync')->dailyAt('02:20');
        });
    }
}
