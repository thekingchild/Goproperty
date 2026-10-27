<?php

declare(strict_types=1);

namespace Modules\Properties\Providers;

use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Properties\Buildings\PropertyDirectoryService;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
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
 * Modules\Properties\Livewire), its workspace sidebar items, and the
 * contracts other modules use (Contracts\PropertyDirectory, UnitDirectory).
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
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(OwnerProfile::class, OwnerProfilePolicy::class);
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Properties\\Livewire');

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
