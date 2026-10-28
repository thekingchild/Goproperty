<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Import\Contracts\ImportReportPanels;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Contracts\ImportUsages;
use Modules\Import\Contracts\SpreadsheetReader;
use Modules\Import\Jobs\PurgeImportUploads;
use Modules\Import\Lists\ImportBatchList;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Policies\ImportBatchPolicy;
use Modules\Import\Reading\Spreadsheets;
use Modules\Import\Targets\ReportPanelRegistry;
use Modules\Import\Targets\TargetRegistry;
use Modules\Import\Targets\UsageRegistry;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Import module (DATA_IMPORT.md, ARCHITECTURE.md § 6.12, § 8): import
 * batches, mappings, the dry run, the queued commit and rollback. Other
 * modules add what can be imported by registering targets into
 * ImportTargets (a singleton) from their own providers, and may read their
 * own spreadsheet uploads through Contracts\SpreadsheetReader (Collections'
 * bank statements, P03-07a). Modules also say whether records an import
 * created are used by their own records (ImportUsages, a singleton: the
 * rollback check) and may offer a step on a committed import's report
 * (ImportReportPanels: "Invite tenants now", P03-13a). This provider adds
 * the policy, the Livewire namespace `import::` (classes in
 * Modules\Import\Livewire), the workspace sidebar item and the daily purge
 * of uploaded files (Schedule::job, ARCHITECTURE.md § 7; registered when
 * the scheduler is built, so before routes/console.php's queue tick).
 */
final class ImportServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Import';

    protected string $nameLower = 'import';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ImportTargets::class, TargetRegistry::class);
        // What other modules say about records an import created (rollback), and the steps they offer on a report.
        $this->app->singleton(ImportUsages::class, UsageRegistry::class);
        $this->app->singleton(ImportReportPanels::class, ReportPanelRegistry::class);
        $this->app->bind(SpreadsheetReader::class, Spreadsheets::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Import\\Livewire');

        // The import history exports (P02-07).
        $this->callAfterResolving(ListExports::class, static fn (ListExports $exports) => $exports->register(ImportBatchList::class));

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'imports',
                label: 'Import',
                icon: 'arrow-up-tray',
                route: 'workspace.imports.index',
                active: ['workspace.imports.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', ImportBatch::class),
                order: 700,
            ));
        });

        // Uploaded files are kept 30 days (DATA_IMPORT.md § 4).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new PurgeImportUploads, 'imports')->name('import-uploads-purge')->dailyAt('03:40');
        });
    }
}
