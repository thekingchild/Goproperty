<?php

declare(strict_types=1);

namespace Modules\Properties\Providers;

use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Policies\OwnerProfilePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Properties module (ARCHITECTURE.md § 6.2, § 8): owner profiles, and
 * later buildings and units. The base provider loads the module's views
 * (`properties::`) and migrations; this one adds its policies, its
 * Livewire components (namespace `properties::`, classes in
 * Modules\Properties\Livewire) and its workspace sidebar items.
 */
final class PropertiesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Properties';

    protected string $nameLower = 'properties';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(OwnerProfile::class, OwnerProfilePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Properties\\Livewire');

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
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
