<?php

declare(strict_types=1);

namespace Modules\Billing\Providers;

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
use Modules\Billing\Bills\BillService;
use Modules\Billing\Bills\Ledger;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Jobs\FlagOverdueBills;
use Modules\Billing\Jobs\GenerateBills;
use Modules\Billing\Lists\BillList;
use Modules\Billing\Models\Bill;
use Modules\Billing\Policies\BillPolicy;
use Modules\Leasing\Contracts\CycleCalculator;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Billing module (ARCHITECTURE.md § 6.4, § 8). The base provider loads
 * the module's views (`billing::`) and migrations; this one binds
 * Contracts\BillLedger (for Payments and Collections), and adds the bill
 * pages (routes, Livewire namespace `billing::`, classes in
 * Modules\Billing\Livewire), BillPolicy, the sidebar item "Bills", the
 * list's export (Lists\BillList), the organization setting
 * `billing.lead_days` (bills are generated that many days before their
 * period starts, default 7), and the daily jobs `bill:generate` (00:10)
 * and `bills:flag-overdue` (01:00) on the `billing` queue (Schedule::job,
 * ARCHITECTURE.md § 7).
 */
final class BillingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Billing';

    protected string $nameLower = 'billing';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(BillLedger::class, Ledger::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Bill::class, BillPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Billing\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(BillService::LEAD_DAYS_SETTING, SettingType::Integer, CycleCalculator::DEFAULT_LEAD_DAYS, ['integer', 'between:0,'.BillService::MAX_LEAD_DAYS], SettingScope::Organization));
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(BillList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'bills',
                label: 'Bills',
                icon: 'banknotes',
                route: 'workspace.bills.index',
                active: ['workspace.bills.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Bill::class),
                order: 460,
            ));
        });

        // Bill generation and the OVERDUE flag (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new GenerateBills, 'billing')->name('bills-generate')->dailyAt('00:10');
            $schedule->job(new FlagOverdueBills, 'billing')->name('bills-flag-overdue')->dailyAt('01:00');
        });
    }
}
