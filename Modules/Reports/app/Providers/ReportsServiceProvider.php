<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Reports\Lists\ArrearsList;
use Modules\Reports\Lists\BuildingPerformanceList;
use Modules\Reports\Lists\CollectionsList;
use Modules\Reports\Lists\ExpensesList;
use Modules\Reports\Lists\OccupancyList;
use Modules\Reports\Lists\RenewalsList;
use Modules\Reports\Policies\ReportPolicy;
use Modules\Reports\Reports\PortfolioReport;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Reports module (P08-01): the customer dashboards on the workspace's
 * Reports page, each report exported to CSV and Excel (the list export)
 * and the whole page to PDF. It owns no table and reads the other modules
 * only through their contracts (ARCHITECTURE.md § 8 rule 3).
 */
final class ReportsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Reports';

    protected string $nameLower = 'reports';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(PortfolioReport::class, ReportPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Reports\\Livewire');

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'reports',
                label: 'Reports',
                icon: 'chart-bar',
                route: 'workspace.reports.index',
                active: ['workspace.reports.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', PortfolioReport::class),
                order: 600,
            ));
        });

        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            foreach ([OccupancyList::class, CollectionsList::class, ArrearsList::class, RenewalsList::class, ExpensesList::class, BuildingPerformanceList::class] as $list) {
                $exports->register($list);
            }
        });
    }
}
