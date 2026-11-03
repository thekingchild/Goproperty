<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\PortalTab;
use App\Navigation\PortalTabs;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Tenancy\Moves\BuildingRecordMovers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\BuildingGroup\Handover\BuildingGroupsMover;
use Modules\BuildingGroup\Jobs\SyncBuildingGroups;
use Modules\BuildingGroup\Listeners\FollowLeases;
use Modules\BuildingGroup\Listeners\OpenBuildingGroup;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Live\GroupChannel;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Policies\BuildingGroupPolicy;
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
 *
 * P07-02 adds posts, comments and reactions: Policies\BuildingGroupPolicy
 * (for groups, posts and comments), the workspace pages (sidebar "Building
 * Groups", behind the plan switch `building_group`) and the portal's
 * (tab "Building Group"), routes/web.php, the Livewire namespace
 * `buildinggroup::`, the list's export (Lists\GroupList) and the media
 * collection `group_images` (the posts' photos: attachments).
 *
 * P07-03 keeps open group pages current (Live\LiveFeed): they poll, and
 * on a VPS Reverb nudges them on the private channel `group.{id}`
 * (Live\GroupChannel, registered here).
 */
final class BuildingGroupServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'BuildingGroup';

    protected string $nameLower = 'buildinggroup';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // The posts' photos (P07-02; ARCHITECTURE.md § 6.8): JPEG, PNG or
        // WebP, 2 MB each, within the media library's pixel caps, with a
        // thumbnail and a large WebP made on the `media` queue. Attachments,
        // not evidence: a photo posted by mistake can really go (§ 9).
        if (! is_array(config('media.collections.'.GroupPost::IMAGES))) {
            config(['media.collections.'.GroupPost::IMAGES => [
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_kb' => 2 * 1024,
                'conversions' => ['thumb', 'large'],
                'kind' => 'attachment',
            ]]);
        }
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(BuildingGroup::class, BuildingGroupPolicy::class);
        Gate::policy(GroupPost::class, BuildingGroupPolicy::class);
        Gate::policy(GroupComment::class, BuildingGroupPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\BuildingGroup\\Livewire');

        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(GroupList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'groups',
                label: 'Building Groups',
                icon: 'chat-bubble-left-right',
                route: 'workspace.groups.index',
                active: ['workspace.groups.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', BuildingGroup::class),
                order: 497,
            ));
        });

        $this->callAfterResolving(PortalTabs::class, static function (PortalTabs $tabs): void {
            $tabs->add(new PortalTab(key: 'groups', label: 'Building Group', icon: 'chat-bubble-left-right', route: 'portal.groups.index', active: ['portal.groups.*'], order: 35));
        });

        foreach (FollowLeases::events() as $event) {
            Event::listen($event, FollowLeases::class);
        }

        Event::listen(PropertyCreated::class, OpenBuildingGroup::class);

        // Live updates on a VPS (P07-03): who may listen to a group's page.
        GroupChannel::register();

        // A management handover: the leaving organization's group closes, the receiving one's opens (BILLING_PAYMENTS.md § 7.4).
        $this->callAfterResolving(BuildingRecordMovers::class, static fn (BuildingRecordMovers $movers) => $movers->register(BuildingGroupsMover::class));

        // The backfill and safety net, after leases:expire-and-flag (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new SyncBuildingGroups, 'default')->name('building-groups-sync')->dailyAt('02:20');
        });
    }
}
