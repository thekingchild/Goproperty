<?php

declare(strict_types=1);

namespace Modules\Tenants\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportTargets;
use Modules\Tenants\Import\TenantsImportTarget;
use Modules\Tenants\Lists\TenantList;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Policies\TenantProfilePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Tenants module (ARCHITECTURE.md § 8): tenant profiles. The base
 * provider loads the module's views (`tenants::`) and migrations; this one
 * adds the policy, the workspace pages (routes, Livewire namespace
 * `tenants::`, classes in Modules\Tenants\Livewire), the sidebar item
 * "Tenants", the list's export (Lists\TenantList, App\Exports\ListExports)
 * and makes tenants importable by registering TenantsImportTarget into the
 * Import module's registry (through its contract, AGENT.md § 4; nothing
 * happens when the Import module is off).
 */
final class TenantsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenants';

    protected string $nameLower = 'tenants';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TenantProfile::class, TenantProfilePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Tenants\\Livewire');

        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(TenantsImportTarget::class);
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(TenantList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'tenants',
                label: 'Tenants',
                icon: 'users',
                route: 'workspace.tenants.index',
                active: ['workspace.tenants.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', TenantProfile::class),
                order: 400,
            ));
        });
    }
}
