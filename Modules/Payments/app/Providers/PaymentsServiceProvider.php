<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use App\Documents\Verification\DocumentVerifiers;
use App\Exports\ListExports;
use App\Models\User;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Billing\Events\BillIssued;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Contracts\ImportUsages;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\TenantProofs;
use Modules\Payments\Contracts\TenantReceipts;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRecorded;
use Modules\Payments\Events\PaymentRejected;
use Modules\Payments\Events\ReceiptVoided;
use Modules\Payments\Import\OpeningBalancesImportTarget;
use Modules\Payments\Import\PaymentHistoryImportTarget;
use Modules\Payments\Import\PaymentUsage;
use Modules\Payments\Listeners\ApplyCreditToNewBill;
use Modules\Payments\Listeners\IssueReceipt;
use Modules\Payments\Listeners\TellTenantAboutPayment;
use Modules\Payments\Lists\PaymentList;
use Modules\Payments\Lists\ReceiptList;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Payments\Recorder;
use Modules\Payments\Policies\PaymentPolicy;
use Modules\Payments\Policies\PaymentProofPolicy;
use Modules\Payments\Policies\ReceiptPolicy;
use Modules\Payments\Proofs\ProofSubmissions;
use Modules\Payments\Proofs\TenantProofRecords;
use Modules\Payments\Receipts\Receipts;
use Modules\Payments\Receipts\ReceiptVerifier;
use Modules\Payments\Receipts\TenantReceiptRecords;
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
 *
 * Receipts (P03-10b): ReceiptPolicy, the receipt pages and the sidebar item
 * "Receipts", their export (Lists\ReceiptList), the `receipt` resolver of
 * the public verify page, the listener that issues a receipt inside the
 * confirming transaction (IssueReceipt), and the one that tells the tenant
 * about every payment transition once it commits (TellTenantAboutPayment).
 *
 * Proofs of payment (P03-09): Contracts\PaymentProofs (for the tenant
 * portal, P03-11b), PaymentProofPolicy, the proof upload page and file
 * routes, and the module's own evidence media collection `payment_proofs`
 * (merged into config `media.collections` here, as config/media.php
 * allows, so the module owns its upload rules).
 *
 * The tenant portal (P03-11b): Contracts\TenantReceipts and
 * Contracts\TenantProofs, a tenant's own receipts (with their kept PDFs)
 * and proofs, read by the Tenants module's /portal pages.
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
        $this->app->bind(PaymentProofs::class, ProofSubmissions::class);
        $this->app->bind(TenantReceipts::class, TenantReceiptRecords::class);
        $this->app->bind(TenantProofs::class, TenantProofRecords::class);
        $this->app->singleton(OpeningBalancesImportTarget::class);
        $this->app->singleton(PaymentHistoryImportTarget::class);

        // Proofs of payment: a screenshot, a photo of a teller slip or a
        // PDF, at most 10 MB; evidence (never deleted; ARCHITECTURE.md § 9).
        if (! is_array(config('media.collections.'.PaymentProofs::COLLECTION))) {
            config(['media.collections.'.PaymentProofs::COLLECTION => [
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

        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Receipt::class, ReceiptPolicy::class);
        Gate::policy(PaymentProof::class, PaymentProofPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Payments\\Livewire');

        Event::listen(BillIssued::class, ApplyCreditToNewBill::class);
        // Inside the confirming transaction: the receipt commits with the confirmation.
        Event::listen(PaymentConfirmed::class, IssueReceipt::class);

        // After each transition commits: the tenant is told once (P03-10b).
        foreach ([PaymentRecorded::class, PaymentConfirmed::class, PaymentRejected::class, ReceiptVoided::class] as $event) {
            Event::listen($event, TellTenantAboutPayment::class);
        }

        // An imported tenant is used once a payment names them (an import's rollback check, P03-13a).
        $this->callAfterResolving(ImportUsages::class, static fn (ImportUsages $usages) => $usages->register(PaymentUsage::class));
        // The Opening balances and Payment history import sheets (P03-13b, DATA_IMPORT.md § 1 rows 6 and 8).
        $this->callAfterResolving(ImportTargets::class, static function (ImportTargets $targets): void {
            $targets->register(OpeningBalancesImportTarget::class);
            $targets->register(PaymentHistoryImportTarget::class);
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(PaymentList::class);
            $exports->register(ReceiptList::class);
        });

        $this->callAfterResolving(DocumentVerifiers::class, static function (DocumentVerifiers $verifiers): void {
            $verifiers->register(Receipts::KIND, ReceiptVerifier::class);
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
            $navigation->add(new WorkspaceNavItem(
                key: 'receipts',
                label: 'Receipts',
                icon: 'document-check',
                route: 'workspace.receipts.index',
                active: ['workspace.receipts.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Receipt::class),
                order: 475,
            ));
        });
    }
}
