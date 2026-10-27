<?php

declare(strict_types=1);

namespace Modules\Collections\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Collections\Lists\StatementLineList;
use Modules\Collections\Lists\StatementList;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Policies\BankStatementImportPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Collections module (ONBOARDING_COMPLIANCE.md § 4.1, ARCHITECTURE.md
 * § 6.4, § 8): bank statement import (P03-07a), matching of credits to
 * bills (P03-07b). The base provider loads the module's views
 * (`collections::`) and migrations; this one adds the statement pages
 * (routes, Livewire namespace `collections::`, classes in
 * Modules\Collections\Livewire), BankStatementImportPolicy, the sidebar
 * item "Bank statements" and the two lists' exports (statements and
 * statement lines).
 */
final class CollectionsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Collections';

    protected string $nameLower = 'collections';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(BankStatementImport::class, BankStatementImportPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Collections\\Livewire');

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(StatementList::class);
            $exports->register(StatementLineList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'statements',
                label: 'Bank statements',
                icon: 'building-library',
                route: 'workspace.statements.index',
                active: ['workspace.statements.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', BankStatementImport::class),
                order: 480,
            ));
        });
    }
}
