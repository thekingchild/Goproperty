<?php

declare(strict_types=1);

namespace Modules\Properties\Providers;

use App\Exports\ListExports;
use App\Members\MemberRemoved;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportTargets;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Assignments\DropRemovedMemberAssignments;
use Modules\Properties\Buildings\PropertyDirectoryService;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
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
use Modules\Properties\Policies\OwnerProfilePolicy;
use Modules\Properties\Policies\PropertyPolicy;
use Modules\Properties\Policies\UnitPolicy;
use Modules\Properties\Units\UnitDirectoryService;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Properties module (ARCHITECTURE.md § 6.2, § 8): owner profiles and
 * buildings and their units. The base provider loads the module's views
 * (`properties::`) and migrations; this one adds its policies, its
 * Livewire components (namespace `properties::`, classes in
 * Modules\Properties\Livewire), its workspace sidebar items, the contracts
 * other modules use (Contracts\PropertyDirectory, UnitDirectory,
 * BuildingAccess), drops a removed member's building assignments
 * (App\Members\MemberRemoved), and makes owners, buildings and units
 * importable by registering their targets into the Import module's
 * registry (through its contract, AGENT.md § 4; nothing happens when the
 * Import module is off), and registers its lists' exports (Lists\*,
 * App\Exports\ListExports).
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

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Properties\\Livewire');

        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(OwnersImportTarget::class);
            $targets->register(PropertiesImportTarget::class);
            $targets->register(UnitsImportTarget::class);
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
