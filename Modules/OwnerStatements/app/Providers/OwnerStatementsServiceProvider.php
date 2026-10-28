<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Providers;

use App\Exports\ListExports;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\OwnerStatements\Contracts\SettledMonths;
use Modules\OwnerStatements\Contracts\TenantContactSharing;
use Modules\OwnerStatements\Lists\OwnerStatementList;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Policies\OwnerStatementPolicy;
use Modules\OwnerStatements\Statements\SettledMonthRecords;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The OwnerStatements module (P08-05): owners' monthly statements in the
 * agency's workspace (an owner's page → Statements) and in the owner
 * portal (/owner/statements), the agency's Settled / Unsettled mark and
 * the statement PDF. It reads the other modules only through their
 * contracts (ARCHITECTURE.md § 8 rule 3).
 */
final class OwnerStatementsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'OwnerStatements';

    protected string $nameLower = 'ownerstatements';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // For other modules (ARCHITECTURE.md § 8 rule 3): Expenses refuses
        // changes to a settled month; the owner portal asks whether to show
        // tenants' contact details.
        $this->app->bind(SettledMonths::class, SettledMonthRecords::class);
        $this->app->bind(TenantContactSharing::class, SettledMonthRecords::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(OwnerStatementSettlement::class, OwnerStatementPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\OwnerStatements\\Livewire');

        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(OwnerStatementList::class);
        });
    }
}
