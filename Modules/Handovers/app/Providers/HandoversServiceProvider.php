<?php

declare(strict_types=1);

namespace Modules\Handovers\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Handovers\Jobs\EndHandovers;
use Modules\Handovers\Jobs\ExpireHandovers;
use Modules\Handovers\Lists\HandoversMadeList;
use Modules\Handovers\Lists\HandoversReceivedList;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Policies\ManagementHandoverPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Handovers module (P03-14; BILLING_PAYMENTS.md § 7, ARCHITECTURE.md
 * § 6.1, § 8): management handovers, P02-04's billing transfers reworked.
 * A landlord's organization hands chosen buildings to an agency; on
 * acceptance the buildings and their live records move into the agency's
 * organization (each module's App\Tenancy\Moves\MovesBuildingRecords, in
 * one transaction), and back when either side ends it with notice. Its
 * own module rather than SaasBilling's, because it is about who manages
 * buildings, not GoProperty's subscription: it reads the bill change
 * through SaasBilling's SubscriptionQuotes contract.
 *
 * The base provider loads the module's views (`handovers::`) and
 * migrations; this one adds the policy, the Livewire namespace
 * `handovers::`, both lists' exports, the sidebar item "Handovers" (owners
 * only) and the two hourly jobs: offers expire after 14 days, and
 * managements whose notice ran out end (Schedule::job, ARCHITECTURE.md
 * § 7).
 */
final class HandoversServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Handovers';

    protected string $nameLower = 'handovers';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ManagementHandover::class, ManagementHandoverPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Handovers\\Livewire');

        // Both lists export (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(HandoversMadeList::class);
            $exports->register(HandoversReceivedList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'handovers',
                label: 'Handovers',
                icon: 'arrows-right-left',
                route: 'workspace.handovers.index',
                active: ['workspace.handovers.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', ManagementHandover::class),
                order: 950,
            ));
        });

        // Offers expire 14 days after they are made; managements end when
        // their notice runs out (BILLING_PAYMENTS.md § 7.2, § 7.6).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new ExpireHandovers, 'billing')->name('handovers-expire')->hourlyAt(20);
            $schedule->job(new EndHandovers, 'billing')->name('handovers-end')->hourlyAt(25);
        });
    }
}
