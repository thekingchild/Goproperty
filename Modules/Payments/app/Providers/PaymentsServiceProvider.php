<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Billing\Events\BillIssued;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Listeners\ApplyCreditToNewBill;
use Modules\Payments\Lists\PaymentList;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\Recorder;
use Modules\Payments\Policies\PaymentPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Payments module (ARCHITECTURE.md § 6.4, § 8). The base provider loads
 * the module's views (`payments::`) and migrations; this one binds
 * Contracts\PaymentRecorder (for Collections and OnlinePayments), and adds
 * the payment pages (routes, Livewire namespace `payments::`, classes in
 * Modules\Payments\Livewire), PaymentPolicy, the sidebar item "Payments",
 * the list's export (Lists\PaymentList), and the listener that applies a
 * tenant's credit to each new bill (Billing's BillIssued, queued on
 * `billing` after the bill's transaction commits).
 */
final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Payments';

    protected string $nameLower = 'payments';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(PaymentRecorder::class, Recorder::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Payment::class, PaymentPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Payments\\Livewire');

        Event::listen(BillIssued::class, ApplyCreditToNewBill::class);

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(PaymentList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'payments',
                label: 'Payments',
                icon: 'credit-card',
                route: 'workspace.payments.index',
                active: ['workspace.payments.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Payment::class),
                order: 470,
            ));
        });
    }
}
