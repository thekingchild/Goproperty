<?php

declare(strict_types=1);

namespace Modules\Leasing\Providers;

use App\Documents\Verification\DocumentVerifiers;
use App\Exports\ListExports;
use App\Models\Organization;
use App\Models\User;
use App\Navigation\SettingsTab;
use App\Navigation\SettingsTabs;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingType;
use App\Tenancy\Moves\BuildingRecordMovers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Contracts\ImportUsages;
use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;
use Modules\Leasing\Contracts\ImportedLeases;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Contracts\LiveLeases;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Leasing\Contracts\TenantLeaseContracts;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Leasing\Contracts\UnitLeases;
use Modules\Leasing\CycleCalculator;
use Modules\Leasing\Documents\ContractClauses;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Documents\LeaseContractVerifier;
use Modules\Leasing\Documents\TenantContractFiles;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Handover\LeasesMover;
use Modules\Leasing\Import\LeaseImportUsage;
use Modules\Leasing\Import\LeasesImportTarget;
use Modules\Leasing\Jobs\ExpireAndFlagLeases;
use Modules\Leasing\Leases\LeaseRecords;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Leases\OwnerLeaseRecords;
use Modules\Leasing\Listeners\IssueLeaseContract;
use Modules\Leasing\Lists\LeaseList;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Policies\LeasePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Leasing module (ARCHITECTURE.md § 6.3, § 8). The base provider loads
 * the module's views (`leasing::`) and migrations; this one binds the
 * contracts other modules use (Contracts\CycleCalculator, AGENT.md § 3
 * rule 3; Contracts\TenantLeases for the Tenants module;
 * Contracts\LeaseBillingSchedule for Billing; Contracts\TenantLeaseContracts
 * for the tenant portal's contract download, P03-11b; Contracts\OwnerLeases
 * for the owner portal, P03-15; Contracts\UnitLeases for maintenance
 * tickets, P04-01), and adds the lease pages
 * (routes, Livewire namespace `leasing::`, classes in
 * Modules\Leasing\Livewire), LeasePolicy, the sidebar item "Leases", the
 * list's export (Lists\LeaseList), the organization settings
 * `leasing.reservation_days` (how long a draft holds its unit, default 14)
 * and `leasing.ending_soon_days` (default 60), and the daily
 * `leases:expire-and-flag` job (Schedule::job, ARCHITECTURE.md § 7).
 *
 * Imports (P03-13a): the Leases sheet (Import\LeasesImportTarget, into the
 * Import module's registry through its contract), Leasing's answer to an
 * import's rollback check (Import\LeaseImportUsage) and
 * Contracts\ImportedLeases.
 *
 * Lease contracts (P03-03b): the listener that issues a contract when a
 * lease is activated and queues its PDF (Listeners\IssueLeaseContract),
 * the `lease_contract` resolver of the public verify page
 * (Documents\LeaseContractVerifier), the organization setting
 * `leasing.contract_clauses` (the agency's clause template, default
 * ContractClauses::DEFAULT) and its page under Settings (SettingsTabs).
 */
final class LeasingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Leasing';

    protected string $nameLower = 'leasing';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(CycleCalculatorContract::class, CycleCalculator::class);
        $this->app->bind(TenantLeases::class, LeaseRecords::class);
        $this->app->bind(LeaseBillingSchedule::class, LeaseRecords::class);
        $this->app->bind(TenantLeaseContracts::class, TenantContractFiles::class);
        $this->app->bind(ImportedLeases::class, LeaseRecords::class);
        // Who holds a unit now, for maintenance tickets raised by staff (P04-01).
        $this->app->bind(UnitLeases::class, LeaseRecords::class);
        $this->app->bind(OwnerLeases::class, OwnerLeaseRecords::class);
        $this->app->bind(LiveLeases::class, LeasesMover::class);
        // One of it, so a dry run's memory of the tenants and units it looked up is kept between rows.
        $this->app->singleton(LeasesImportTarget::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Lease::class, LeasePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Leasing\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(LeaseService::RESERVATION_DAYS_SETTING, SettingType::Integer, LeaseService::DEFAULT_RESERVATION_DAYS, ['integer', 'between:1,90'], SettingScope::Organization));
            $settings->define(new SettingDefinition(LeaseService::ENDING_SOON_DAYS_SETTING, SettingType::Integer, LeaseService::DEFAULT_ENDING_SOON_DAYS, ['integer', 'between:1,365'], SettingScope::Organization));
            // Plain text, never compiled or evaluated (AGENT.md § 3 rule 10).
            $settings->define(new SettingDefinition(ContractClauses::SETTING, SettingType::String, ContractClauses::DEFAULT, ['required', 'string', 'max:'.ContractClauses::MAX_LENGTH], SettingScope::Organization));
        });

        // Ongoing leases are importable (P03-13a); their rows tell imports whether units and tenants are used.
        $this->callAfterResolving(ImportTargets::class, static fn (ImportTargets $targets) => $targets->register(LeasesImportTarget::class));
        $this->callAfterResolving(ImportUsages::class, static fn (ImportUsages $usages) => $usages->register(LeaseImportUsage::class));

        // A management handover moves the buildings' live leases with their deposits (BILLING_PAYMENTS.md § 7.4).
        $this->callAfterResolving(BuildingRecordMovers::class, static fn (BuildingRecordMovers $movers) => $movers->register(LeasesMover::class));

        // Contracts: issued after an activation commits, their PDF queued on `pdf`.
        Event::listen(LeaseActivated::class, IssueLeaseContract::class);

        $this->callAfterResolving(DocumentVerifiers::class, static function (DocumentVerifiers $verifiers): void {
            $verifiers->register(LeaseContracts::KIND, LeaseContractVerifier::class);
        });

        $this->callAfterResolving(SettingsTabs::class, static function (SettingsTabs $tabs): void {
            $tabs->add(new SettingsTab(
                key: 'lease-contract',
                label: 'Lease contract',
                route: 'workspace.settings.documents.lease-contract',
                visible: static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization),
                order: 40,
            ));
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(LeaseList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'leases',
                label: 'Leases',
                icon: 'document-text',
                route: 'workspace.leases.index',
                active: ['workspace.leases.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Lease::class),
                order: 450,
            ));
        });

        // End-date transitions and renewal nudges (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new ExpireAndFlagLeases, 'default')->name('leases-expire-and-flag')->dailyAt('02:00');
        });
    }
}
