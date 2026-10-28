<?php

declare(strict_types=1);

namespace Modules\Tenants\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MovedTenantsChanged;
use App\Tenancy\PortalLanding;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Billing\Events\BillVoided;
use Modules\Import\Contracts\ImportReportPanels;
use Modules\Import\Contracts\ImportTargets;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Tenants\Contracts\ImportedTenants;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tenants\Handover\LeftPortalLinksRevoker;
use Modules\Tenants\Handover\TenantsMover;
use Modules\Tenants\Import\ImportedTenantLookup;
use Modules\Tenants\Import\TenantsImportTarget;
use Modules\Tenants\Jobs\ArchiveSettledMovedTenants as ArchiveSettledMovedTenantsJob;
use Modules\Tenants\Listeners\ArchiveSettledMovedTenants;
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
 * happens when the Import module is off), with Contracts\ImportedTenants
 * for the Leases sheet and "Invite tenants now" on a committed import's
 * report (Livewire\ImportInvitations, P03-13a). Tenants' portal access
 * (P03-01b, Portal\*): invitations and one-time sign-in links on Core
 * invitations, their public pages (routes), and the landing: someone
 * with no workspace who is a tenant somewhere lands on /portal
 * (App\Tenancy\PortalLanding). The tenant portal itself (P03-11a,
 * routes; Livewire\Portal\*): my rentals, my bills and a bill with how
 * to pay it, a lease, across every organization the user rents from
 * (Portal\PortalTenancies, TenantProfilePolicy::viewAsTenant).
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
        // How other modules' import sheets name a tenant (the Leases sheet, P03-13a).
        $this->app->bind(ImportedTenants::class, ImportedTenantLookup::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TenantProfile::class, TenantProfilePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Tenants\\Livewire');

        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(TenantsImportTarget::class);
        });

        // "Invite tenants now" on a committed import's report: never automatic (DATA_IMPORT.md § 2.6, P03-13a).
        $this->callAfterResolving(ImportReportPanels::class, static fn (ImportReportPanels $panels) => $panels->register('tenants::import-invitations'));

        // Every list of the module exports (P02-07).
        // A management handover brings the tenants of the moved buildings' live leases along (BILLING_PAYMENTS.md § 7.4).
        $this->callAfterResolving(BuildingRecordMovers::class, static function (BuildingRecordMovers $movers): void {
            $movers->register(TenantsMover::class);
            // Portal links left on the side moved tenants left are revoked (P04-04).
            $movers->register(LeftPortalLinksRevoker::class);
        });

        // The record a moved tenant left behind archives itself once nothing is owed either way (§ 7.4, P04-04).
        Event::listen(PaymentConfirmed::class, ArchiveSettledMovedTenants::class);
        Event::listen(BillVoided::class, ArchiveSettledMovedTenants::class);
        Event::listen(MovedTenantsChanged::class, ArchiveSettledMovedTenants::class);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new ArchiveSettledMovedTenantsJob, 'default')->name('tenants-archive-moved')->dailyAt('03:50');
        });

        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(TenantList::class);
        });

        // Tenants never get an organization_user row (P03 decision 4): this is how they land on /portal.
        $this->callAfterResolving(PortalLanding::class, static function (PortalLanding $landing, $app): void {
            $landing->register('portal.home', static fn (User $user): bool => $app->make(LinkedTenantProfiles::class)->any($user), 'My rentals', 'home-modern');
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
