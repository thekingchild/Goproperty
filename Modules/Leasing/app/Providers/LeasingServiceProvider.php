<?php

declare(strict_types=1);

namespace Modules\Leasing\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Leasing\CycleCalculator;
use Modules\Leasing\Jobs\ExpireAndFlagLeases;
use Modules\Leasing\Leases\LeaseRecords;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Lists\LeaseList;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Policies\LeasePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Leasing module (ARCHITECTURE.md § 6.3, § 8). The base provider loads
 * the module's views (`leasing::`) and migrations; this one binds the
 * contracts other modules use (Contracts\CycleCalculator, AGENT.md § 3
 * rule 3; Contracts\TenantLeases for the Tenants module;
 * Contracts\LeaseBillingSchedule for Billing), and adds the lease pages
 * (routes, Livewire namespace `leasing::`, classes in
 * Modules\Leasing\Livewire), LeasePolicy, the sidebar item "Leases", the
 * list's export (Lists\LeaseList), the organization settings
 * `leasing.reservation_days` (how long a draft holds its unit, default 14)
 * and `leasing.ending_soon_days` (default 60), and the daily
 * `leases:expire-and-flag` job (Schedule::job, ARCHITECTURE.md § 7).
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
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Lease::class, LeasePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Leasing\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(LeaseService::RESERVATION_DAYS_SETTING, SettingType::Integer, LeaseService::DEFAULT_RESERVATION_DAYS, ['integer', 'between:1,90'], SettingScope::Organization));
            $settings->define(new SettingDefinition(LeaseService::ENDING_SOON_DAYS_SETTING, SettingType::Integer, LeaseService::DEFAULT_ENDING_SOON_DAYS, ['integer', 'between:1,365'], SettingScope::Organization));
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
