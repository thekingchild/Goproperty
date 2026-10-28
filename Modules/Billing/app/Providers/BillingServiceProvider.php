<?php

declare(strict_types=1);

namespace Modules\Billing\Providers;

use App\Documents\Verification\DocumentVerifiers;
use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Billing\Bills\BillIssuer;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Bills\Ledger;
use Modules\Billing\Bills\OpeningBalanceBillRecords;
use Modules\Billing\Bills\OwnerBillRecords;
use Modules\Billing\Bills\TenantBillRecords;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\OpeningBalanceBills;
use Modules\Billing\Contracts\OwnerBills;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\Billing\Contracts\TenantBills;
use Modules\Billing\Documents\InvoiceVerifier;
use Modules\Billing\Events\BillIssued;
use Modules\Billing\Events\BillVoided;
use Modules\Billing\Import\BillUsage;
use Modules\Billing\Import\UtilitiesImportTarget;
use Modules\Billing\Jobs\FlagOverdueBills;
use Modules\Billing\Jobs\GenerateBills;
use Modules\Billing\Listeners\QueueInvoicePdf;
use Modules\Billing\Lists\BillList;
use Modules\Billing\Lists\UtilityReadingList;
use Modules\Billing\Lists\UtilitySettingList;
use Modules\Billing\Lists\UtilityTypeList;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Billing\Models\UtilityType;
use Modules\Billing\Policies\BillPolicy;
use Modules\Billing\Policies\UnitUtilitySettingPolicy;
use Modules\Billing\Policies\UtilityReadingPolicy;
use Modules\Billing\Policies\UtilityTypePolicy;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Contracts\ImportUsages;
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
 *
 * Invoices (P03-05c): the `invoice` verification resolver
 * (Documents\InvoiceVerifier) for /verify, the invoice PDF queued on `pdf`
 * after a bill is issued or voided (Listeners\QueueInvoicePdf), the
 * payment options slot of the pay page (Contracts\PaymentOptions, filled
 * by OnlinePayments in P03-08), and the public pay page /pay/{reference}
 * (routes).
 *
 * Tenant portal (P03-11a): Contracts\TenantBills, a tenant's own bills,
 * their pay instructions and invoice page for the Tenants module's /portal.
 *
 * Utilities (P03-06a): utility types, the units' utilities and meter
 * readings — their policies, pages (routes), the sidebar item "Utilities",
 * the three lists' exports, and the meter photos' evidence media
 * collection `meter_photos` (merged into config `media.collections`).
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
        $this->app->bind(OpeningBalanceBills::class, OpeningBalanceBillRecords::class);
        $this->app->singleton(UtilitiesImportTarget::class);
        $this->app->bind(TenantBills::class, TenantBillRecords::class);
        // The owner portal's read-only view of an owner's buildings' bills (P03-15).
        $this->app->bind(OwnerBills::class, OwnerBillRecords::class);
        $this->app->singleton(PaymentOptions::class);

        // Meter photos (P03-06a): a phone photo of the meter, at most 10 MB;
        // evidence of the reading, never deleted (ARCHITECTURE.md § 9).
        if (! is_array(config('media.collections.'.UtilityReading::PHOTO_COLLECTION))) {
            config(['media.collections.'.UtilityReading::PHOTO_COLLECTION => [
                'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
                'max_kb' => 10 * 1024,
                'conversions' => ['thumb'],
                'kind' => 'evidence',
            ]]);
        }
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Bill::class, BillPolicy::class);
        Gate::policy(UtilityType::class, UtilityTypePolicy::class);
        Gate::policy(UnitUtilitySetting::class, UnitUtilitySettingPolicy::class);
        Gate::policy(UtilityReading::class, UtilityReadingPolicy::class);

        Event::listen(BillIssued::class, QueueInvoicePdf::class);
        Event::listen(BillVoided::class, QueueInvoicePdf::class);

        // Imported leases, tenants and units are used once a bill names them (an import's rollback check, P03-13a).
        $this->callAfterResolving(ImportUsages::class, static fn (ImportUsages $usages) => $usages->register(BillUsage::class));
        // The Utilities import sheet (P03-13b, DATA_IMPORT.md § 1 row 7).
        $this->callAfterResolving(ImportTargets::class, static fn (ImportTargets $targets) => $targets->register(UtilitiesImportTarget::class));

        $this->callAfterResolving(DocumentVerifiers::class, static function (DocumentVerifiers $verifiers): void {
            $verifiers->register(BillIssuer::VERIFICATION_KIND, InvoiceVerifier::class);
        });

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Billing\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(BillService::LEAD_DAYS_SETTING, SettingType::Integer, CycleCalculator::DEFAULT_LEAD_DAYS, ['integer', 'between:0,'.BillService::MAX_LEAD_DAYS], SettingScope::Organization));
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(BillList::class);
            $exports->register(UtilityTypeList::class);
            $exports->register(UtilitySettingList::class);
            $exports->register(UtilityReadingList::class);
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
            $navigation->add(new WorkspaceNavItem(
                key: 'utilities',
                label: 'Utilities',
                icon: 'bolt',
                route: 'workspace.utilities.index',
                active: ['workspace.utilities.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', UtilityType::class),
                order: 465,
            ));
        });

        // Bill generation and the OVERDUE flag (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new GenerateBills, 'billing')->name('bills-generate')->dailyAt('00:10');
            $schedule->job(new FlagOverdueBills, 'billing')->name('bills-flag-overdue')->dailyAt('01:00');
        });
    }
}
