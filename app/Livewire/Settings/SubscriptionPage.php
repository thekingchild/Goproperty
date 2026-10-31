<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Subscription;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\Features;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionOverview;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\AllowedWhileSuspended;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\SaasBilling\Contracts\BankTransfers;
use Modules\SaasBilling\Contracts\CheckoutRefused;
use Modules\SaasBilling\Contracts\SubscriptionInvoice;
use Modules\SaasBilling\Contracts\SubscriptionInvoices;
use Modules\SaasBilling\Contracts\SubscriptionPayments;

/**
 * Settings → Subscription, /o/{organization}/settings/subscription
 * (P06-01b; BILLING_PAYMENTS.md § 1–2): the plan and its state (trial days
 * left), today's monthly bill for the units counted, a scheduled change of
 * the plan's terms (the 30 days' notice), what the plan includes with the
 * staff users used, the other plans at the organization's units, and
 * GoProperty's latest invoices with their lines (P06-02, through
 * SaasBilling's Contracts\SubscriptionInvoices), each open one with a Pay
 * button (P06-03: owners, through GoProperty's Paystack checkout,
 * Contracts\SubscriptionPayments; also while the organization is
 * suspended, never while staff impersonate) and, once GoProperty's account
 * is set, the account to pay into by bank transfer with the invoice number
 * as the narration and **Send proof of transfer** (P06-04, through
 * Contracts\BankTransfers; owners, under the same rules as Pay). A proof
 * waits for GoProperty's staff: it never marks the invoice paid.
 *
 * Seeing it: SubscriptionPolicy::viewAny (owners, administrators). Moving
 * to another plan: owners (`organization.billing`), never while GoProperty
 * staff impersonate nor while the organization is read-only; at once,
 * through SubscriptionService::changePlan(), which refuses a plan whose
 * limits the organization is over.
 */
#[Layout('layouts::app')]
#[Title('Subscription')]
final class SubscriptionPage extends Component
{
    use WithFileUploads {
        _startUpload as private startUploadWhileReadOnly;
        _finishUpload as private finishUploadWhileReadOnly;
        _uploadErrored as private uploadErroredWhileReadOnly;
        _removeUpload as private removeUploadWhileReadOnly;
    }

    #[Locked]
    public int $organizationId;

    public ?string $notice = null;

    /** The invoice whose proof of transfer is being filled in (P06-04), if any. */
    #[Locked]
    public ?string $transferInvoice = null;

    public string $amount = '';

    public string $transferredOn = '';

    public string $senderName = '';

    public string $senderBank = '';

    public string $narration = '';

    /** @var TemporaryUploadedFile|null */
    public $file;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Subscription::class);
    }

    #[BlockedWhileImpersonating]
    public function changePlan(string $key, PlanCatalogue $plans, SubscriptionService $subscriptions): void
    {
        $this->authorize('changePlan', Subscription::class);
        $this->resetErrorBag('plan');
        $this->notice = null;
        $plan = $plans->find($key);

        if (! $plan instanceof Plan || ! $plan->offered) {
            $this->addError('plan', __('That plan is not offered.'));

            return;
        }

        try {
            $subscriptions->changePlan($this->user(), $plan);
        } catch (SubscriptionChangeRefused $refused) {
            $this->addError('plan', $refused->getMessage());

            return;
        }

        $this->notice = __('You are now on :plan.', ['plan' => $plan->name]);
    }

    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function pay(string $number, OrganizationContext $context): void
    {
        $this->authorize('pay', Subscription::class);
        $this->resetErrorBag('pay');
        $payments = $this->payments();

        if ($payments === null || ! $payments->available()) {
            $this->addError('pay', __('Paying online is not available yet. Pay by bank transfer instead.'));

            return;
        }

        try {
            $url = $payments->start($this->user(), $number, route('workspace.settings.subscription.paid', ['organization' => $context->get()->slug]));
        } catch (CheckoutRefused $refused) {
            $this->addError('pay', $refused->getMessage());

            return;
        }

        $this->redirect($url);
    }

    /** Opens the proof-of-transfer form for an open invoice, filled with its total, today and the organization's name. */
    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function startTransfer(string $number, OrganizationContext $context): void
    {
        $this->authorize('pay', Subscription::class);
        $this->resetErrorBag();
        $invoice = collect($this->invoices())->first(static fn (SubscriptionInvoice $invoice): bool => $invoice->number === $number && $invoice->status === 'open');
        abort_unless($invoice instanceof SubscriptionInvoice, 404);
        $kobo = $invoice->total->kobo;

        $this->transferInvoice = $invoice->number;
        $this->amount = number_format(intdiv($kobo, 100)).($kobo % 100 === 0 ? '' : '.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT));
        $this->transferredOn = now('Africa/Lagos')->toDateString();
        $this->senderName = $context->get()->name;
        $this->senderBank = '';
        $this->narration = $invoice->number;
        $this->file = null;
    }

    /*
     * Livewire's upload steps, allowed while the organization is read-only
     * (OrganizationWriteGuard refuses other calls): a suspended customer
     * sends a proof to become active again. They only keep a temporary
     * file; sendTransfer() is what records anything.
     */

    /** @param  array<mixed>  $fileInfo */
    #[AllowedWhileSuspended]
    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        $this->startUploadWhileReadOnly($name, $fileInfo, $isMultiple);
    }

    #[AllowedWhileSuspended]
    public function _finishUpload(mixed $name, mixed $tmpPath, mixed $isMultiple, mixed $append = true): void
    {
        $this->finishUploadWhileReadOnly($name, $tmpPath, $isMultiple, $append);
    }

    #[AllowedWhileSuspended]
    public function _uploadErrored(mixed $name, mixed $errorsInJson, mixed $isMultiple): void
    {
        $this->uploadErroredWhileReadOnly($name, $errorsInJson, $isMultiple);
    }

    #[AllowedWhileSuspended]
    public function _removeUpload(mixed $name, mixed $tmpFilename): void
    {
        $this->removeUploadWhileReadOnly($name, $tmpFilename);
    }

    #[AllowedWhileSuspended]
    public function cancelTransfer(): void
    {
        $this->transferInvoice = null;
        $this->file = null;
        $this->resetErrorBag();
    }

    /** Sends the proof: a payment waiting for GoProperty's staff; the invoice stays open until they confirm it. */
    #[AllowedWhileSuspended]
    #[BlockedWhileImpersonating]
    public function sendTransfer(): void
    {
        $this->authorize('pay', Subscription::class);
        $transfers = app()->bound(BankTransfers::class) ? app(BankTransfers::class) : null;

        if ($transfers === null || $this->transferInvoice === null) {
            $this->addError('invoice', __('Paying by bank transfer is not available yet. Contact GoProperty.'));

            return;
        }

        if (! $this->file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Choose the proof: a screenshot, a photo or a PDF of the transfer.')]);
        }

        $transfers->submit($this->user(), $this->transferInvoice, [
            'amount' => $this->amount,
            'transferredOn' => $this->transferredOn,
            'senderName' => $this->senderName,
            'senderBank' => $this->senderBank,
            'narration' => $this->narration,
        ], $this->file);

        if ($this->file->exists()) {
            $this->file->delete();
        }

        $this->notice = __('Proof sent for invoice :number. GoProperty will check its account and confirm the payment; the invoice stays open until then.', ['number' => $this->transferInvoice]);
        $this->transferInvoice = null;
        $this->file = null;
    }

    public function render(SubscriptionOverview $overview, PlanCatalogue $plans, Features $features): View
    {
        $plan = $overview->plan();
        $units = $overview->units();

        return view('livewire.settings.subscription-page', [
            'subscription' => $overview->subscription(),
            'plan' => $plan,
            'units' => $units,
            'monthly' => $plan?->priceFor($units),
            'vat' => $plans->vatPercent(),
            'change' => $overview->scheduledChange(),
            'features' => array_map(static fn (Feature $feature): array => [
                'label' => $feature->label(),
                'value' => $features->value($feature)->describe($feature),
                'key' => $feature->value,
            ], Feature::cases()),
            'staffUsed' => $overview->staffUsed(),
            'staffLimit' => $overview->staffLimit(),
            'choices' => array_values(array_filter($plans->plans(), static fn (Plan $choice): bool => ! $choice->custom)),
            'canChange' => $this->user()->can('changePlan', Subscription::class),
            'invoices' => $this->invoices(),
            'canPay' => $this->user()->can('pay', Subscription::class) && ($this->payments()?->available() ?? false),
            'transferAccount' => $account = (app()->bound(BankTransfers::class) ? app(BankTransfers::class)->account() : null),
            'canSendProof' => $account !== null && $this->user()->can('pay', Subscription::class),
            'status' => is_array($status = session('subscription_status')) && count($status) === 2 ? $status : null,
        ]);
    }

    /** @return list<SubscriptionInvoice> */
    private function invoices(): array
    {
        return app()->bound(SubscriptionInvoices::class) ? app(SubscriptionInvoices::class)->recent() : [];
    }

    private function payments(): ?SubscriptionPayments
    {
        return app()->bound(SubscriptionPayments::class) ? app(SubscriptionPayments::class) : null;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
