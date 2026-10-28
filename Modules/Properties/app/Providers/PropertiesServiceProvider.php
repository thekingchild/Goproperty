<?php

declare(strict_types=1);

namespace Modules\Properties\Providers;

use App\Exports\ListExports;
use App\Members\MemberRemoved;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\PortalLanding;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportTargets;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Events\LeaseDrafted;
use Modules\Leasing\Events\LeaseExpired;
use Modules\Leasing\Events\LeaseImported;
use Modules\Leasing\Events\LeaseImportRolledBack;
use Modules\Leasing\Events\LeaseRenewed;
use Modules\Leasing\Events\LeaseTerminated;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Assignments\DropRemovedMemberAssignments;
use Modules\Properties\Buildings\PropertyDirectoryService;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\ImportedUnits;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Handover\BuildingsMover;
use Modules\Properties\Import\ImportedUnitLookup;
use Modules\Properties\Import\OwnersImportTarget;
use Modules\Properties\Import\PropertiesImportTarget;
use Modules\Properties\Import\UnitsImportTarget;
use Modules\Properties\Lists\BuildingAgentList;
use Modules\Properties\Lists\OwnerList;
use Modules\Properties\Lists\PropertyList;
use Modules\Properties\Lists\UnitList;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Properties\OwnerPortal\LinkedOwnerProfiles;
use Modules\Properties\OwnerPortal\ReadOnlyOwnerPage;
use Modules\Properties\Policies\OwnerProfilePolicy;
use Modules\Properties\Policies\PropertyPolicy;
use Modules\Properties\Policies\UnitPolicy;
use Modules\Properties\Units\FollowLeases;
use Modules\Properties\Units\UnitDirectoryService;
use Nwidart\Modules\Support\ModuleServiceProvider;

use function Livewire\before;

/**
 * The Properties module (ARCHITECTURE.md § 6.2, § 8): owner profiles and
 * buildings and their units. The base provider loads the module's views
 * (`properties::`) and migrations; this one adds its policies, its
 * Livewire components (namespace `properties::`, classes in
 * Modules\Properties\Livewire), its workspace sidebar items, the contracts
 * other modules use (Contracts\PropertyDirectory, UnitDirectory,
 * BuildingAccess, ImportedUnits for the Leases import sheet), drops a
 * removed member's building assignments (App\Members\MemberRemoved),
 * keeps units' occupied/reserved status in step with the Leasing module's
 * lease events (Units\FollowLeases), and
 * moves buildings and units in a management handover (Handover\BuildingsMover,
 * registered into App\Tenancy\Moves\BuildingRecordMovers), and
 * makes owners, buildings and units importable by registering their
 * targets into the Import module's
 * registry (through its contract, AGENT.md § 4; nothing happens when the
 * Import module is off), and registers its lists' exports (Lists\*,
 * App\Exports\ListExports).
 *
 * The owner portal (P03-15, OwnerPortal\*, routes, Livewire\OwnerPortal\*):
 * owner invitations on Core invitations and their public page, the owner's
 * access panel on the owner page (Livewire\OwnerPortalAccess), the
 * read-only /owner pages across organizations (OwnerPortal\OwnerPortfolios),
 * the landing (someone with no workspace who is an owner somewhere lands on
 * /owner, App\Tenancy\PortalLanding), and the refusal of every method call
 * to the portal's pages (OwnerPortal\ReadOnlyOwnerPage).
 */
final class PropertiesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Properties';

    protected string $nameLower = 'properties';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // For other modules (ARCHITECTURE.md § 6.2, § 8 rule 3).
        $this->app->bind(PropertyDirectory::class, PropertyDirectoryService::class);
        $this->app->bind(UnitDirectory::class, UnitDirectoryService::class);
        $this->app->bind(BuildingAccess::class, AgentNarrowing::class);
        // How other modules' import sheets name a unit (the Leases sheet, P03-13a).
        $this->app->bind(ImportedUnits::class, ImportedUnitLookup::class);

        // One of each, so a dry run's memory of the names a sheet adds is
        // shared with the sheets that refer to them.
        $this->app->singleton(OwnersImportTarget::class);
        $this->app->singleton(PropertiesImportTarget::class);
        $this->app->singleton(UnitsImportTarget::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(OwnerProfile::class, OwnerProfilePolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);

        Event::listen(MemberRemoved::class, DropRemovedMemberAssignments::class);

        // Occupied and reserved follow leases (ARCHITECTURE.md § 6.2): the
        // Leasing module's events carry the status, inside its transaction.
        foreach ([LeaseDrafted::class, LeaseActivated::class, LeaseRenewed::class, LeaseTerminated::class, LeaseExpired::class, LeaseImported::class, LeaseImportRolledBack::class] as $event) {
            Event::listen($event, FollowLeases::class);
        }

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Properties\\Livewire');

        // The owner portal is read-only (P03-15): no method of its pages may be
        // called, whatever a request sends; refreshing is all there is.
        before('call', static function (mixed $component, mixed $method): void {
            if ($component instanceof ReadOnlyOwnerPage && $method !== '$refresh') {
                abort(403);
            }
        });

        // Owners hold only `property-owner` where they are owners: this is how they land on /owner.
        $this->callAfterResolving(PortalLanding::class, static function (PortalLanding $landing, $app): void {
            $landing->register('owner.home', static fn (User $user): bool => $app->make(LinkedOwnerProfiles::class)->any($user), 'My properties', 'key');
        });

        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(OwnersImportTarget::class);
            $targets->register(PropertiesImportTarget::class);
            $targets->register(UnitsImportTarget::class);
        });

        // A management handover moves buildings and their units (BILLING_PAYMENTS.md § 7.4).
        $this->callAfterResolving(BuildingRecordMovers::class, static function (BuildingRecordMovers $movers): void {
            $movers->register(BuildingsMover::class);
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(OwnerList::class);
            $exports->register(PropertyList::class);
            $exports->register(UnitList::class);
            $exports->register(BuildingAgentList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'buildings',
                label: 'Buildings',
                icon: 'building-office-2',
                route: 'workspace.buildings.index',
                active: ['workspace.buildings.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Property::class),
                order: 200,
            ));
            $navigation->add(new WorkspaceNavItem(
                key: 'units',
                label: 'Units',
                icon: 'home-modern',
                route: 'workspace.units.index',
                active: ['workspace.units.index'],
                visible: static fn (User $user): bool => $user->can('viewAny', Unit::class),
                order: 250,
            ));
            $navigation->add(new WorkspaceNavItem(
                key: 'owners',
                label: 'Owners',
                icon: 'user-group',
                route: 'workspace.owners.index',
                active: ['workspace.owners.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', OwnerProfile::class),
                order: 300,
            ));
        });
    }
}
