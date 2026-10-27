<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\SaasBilling\Jobs\ExpireBillingTransfers;
use Modules\SaasBilling\Lists\BillingTransfersMadeList;
use Modules\SaasBilling\Lists\BillingTransfersReceivedList;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Policies\BillingTransferRequestPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The SaasBilling module (ARCHITECTURE.md § 6.1, § 8; BILLING_PAYMENTS.md):
 * GoProperty's own subscription billing. Built so far: org-to-org billing
 * transfers (P02-04). The base provider loads the module's views
 * (`saasbilling::`) and migrations; this one adds the policy, the Livewire
 * namespace `saasbilling::` (classes in Modules\SaasBilling\Livewire), the
 * workspace sidebar item, and the hourly expiry of unanswered offers
 * (Schedule::job, ARCHITECTURE.md § 7; registered when the scheduler is
 * built, so before routes/console.php's queue tick).
 */
final class SaasBillingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'SaasBilling';

    protected string $nameLower = 'saasbilling';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(BillingTransferRequest::class, BillingTransferRequestPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\SaasBilling\\Livewire');

        // Both billing transfer lists export (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(BillingTransfersMadeList::class);
            $exports->register(BillingTransfersReceivedList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'billing-transfers',
                label: 'Billing transfers',
                icon: 'arrows-right-left',
                route: 'workspace.billing-transfers.index',
                active: ['workspace.billing-transfers.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', BillingTransferRequest::class),
                order: 950,
            ));
        });

        // Offers expire 14 days after they are made (BILLING_PAYMENTS.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new ExpireBillingTransfers, 'billing')->name('billing-transfers-expire')->hourlyAt(20);
        });
    }
}
