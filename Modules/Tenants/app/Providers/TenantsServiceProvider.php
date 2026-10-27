<?php

declare(strict_types=1);

namespace Modules\Tenants\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Tenancy\PortalLanding;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportTargets;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Import\TenantsImportTarget;
use Modules\Tenants\Lists\TenantList;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Policies\TenantProfilePolicy;
use Modules\Tenants\Portal\LinkedTenantProfiles;
use Modules\Tenants\Profiles\TenantDirectoryService;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Tenants module (ARCHITECTURE.md § 8): tenant profiles. The base
 * provider loads the module's views (`tenants::`) and migrations; this one
 * binds Contracts\TenantDirectory (how other modules read tenants), adds
 * the policy, the workspace pages (routes, Livewire namespace
 * `tenants::`, classes in Modules\Tenants\Livewire), the sidebar item
 * "Tenants", the list's export (Lists\TenantList, App\Exports\ListExports)
 * and makes tenants importable by registering TenantsImportTarget into the
 * Import module's registry (through its contract, AGENT.md § 4; nothing
 * happens when the Import module is off). Tenants' portal access
 * (P03-01b, Portal\*): invitations and one-time sign-in links on Core
 * invitations, their public pages (routes), and the landing: someone
 * with no workspace who is a tenant somewhere lands on /portal
 * (App\Tenancy\PortalLanding).
 */
final class TenantsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenants';

    protected string $nameLower = 'tenants';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // For other modules (ARCHITECTURE.md § 8 rule 3): Leasing first.
        $this->app->bind(TenantDirectory::class, TenantDirectoryService::class);
    }

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

        // Tenants never get an organization_user row (P03 decision 4): this is how they land on /portal.
        $this->callAfterResolving(PortalLanding::class, static function (PortalLanding $landing, $app): void {
            $landing->register('portal.home', static fn (User $user): bool => $app->make(LinkedTenantProfiles::class)->any($user));
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
