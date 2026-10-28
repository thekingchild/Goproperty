<?php

declare(strict_types=1);

namespace Modules\Expenses\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Expenses\Contracts\OwnerChargedExpenses;
use Modules\Expenses\Lists\ExpenseList;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Policies\ExpensePolicy;
use Modules\Expenses\Recording\OwnerChargedExpenseRecords;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Expenses module (P03-12; DATABASE_SCHEMA.md § 4 `expenses`,
 * ARCHITECTURE.md § 5 "Expenses", § 8): money the organization spent, by
 * building or its own, with receipt files kept as evidence. The base
 * provider loads the module's views (`expenses::`) and migrations; this one
 * adds the expense pages (routes, Livewire namespace `expenses::`, classes
 * in Modules\Expenses\Livewire), ExpensePolicy, the sidebar item
 * "Expenses", the list's export and the module's own evidence media
 * collection `expense_receipts` (merged into config `media.collections`
 * here, as config/media.php allows, so the module owns its upload rules).
 *
 * Reports on expenses (by category, per building) come in P08; the
 * `expenses` feature key (DATABASE_SCHEMA.md `plan_feature`) is not gated
 * yet (P06-01).
 */
final class ExpensesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Expenses';

    protected string $nameLower = 'expenses';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // The expenses charged to buildings' owners, for the owner statements (P08-05).
        $this->app->bind(OwnerChargedExpenses::class, OwnerChargedExpenseRecords::class);

        // Expense receipts: a photo, a scan or a PDF of a vendor's receipt
        // or invoice, at most 10 MB; evidence (never deleted; ARCHITECTURE.md § 9).
        if (! is_array(config('media.collections.'.Expense::COLLECTION))) {
            config(['media.collections.'.Expense::COLLECTION => [
                'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                'max_kb' => 10 * 1024,
                'conversions' => ['thumb'],
                'kind' => 'evidence',
            ]]);
        }
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Expense::class, ExpensePolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Expenses\\Livewire');

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(ExpenseList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'expenses',
                label: 'Expenses',
                icon: 'wallet',
                route: 'workspace.expenses.index',
                active: ['workspace.expenses.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Expense::class),
                order: 490,
            ));
        });
    }
}
