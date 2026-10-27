<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Contracts\PayerType;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Models\Payment;
use Modules\Payments\Payments\PaymentDates;
use Modules\Payments\Payments\PaymentService;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Record a payment, /o/{organization}/payments/create (optionally
 * `?tenant={id}` or `?bill={id}`): a bank transfer, cash or POS the tenant
 * paid to the organization, for the tenant and optionally one of their
 * open bills. "Record" puts it in the confirmation queue; "Record and
 * confirm" confirms it at once (the money is in hand). Cash and POS are
 * always confirmed as they are recorded (P03-09, ONBOARDING_COMPLIANCE.md
 * § 4.3: the member took the money, with a note saying who and where), so
 * the receipt goes straight to the tenant, who can check it. PaymentPolicy::create
 * and ::confirm (`payments.confirm`), checked again by
 * Payments\PaymentService; never while staff impersonate. Tenants come from
 * the Tenants module's directory and bills from Billing's ledger (narrowed
 * to an assigned agent's), never their tables.
 */
#[Layout('layouts::app')]
#[Title('Record a payment')]
final class PaymentForm extends Component
{
    #[Locked]
    public int $organizationId;

    #[Url(as: 'tenant', except: '')]
    public string $tenantId = '';

    #[Url(as: 'bill', except: '')]
    public string $billId = '';

    /** Naira as typed. */
    public string $amount = '';

    public string $method = 'bank_transfer';

    public string $paidAt = '';

    public string $payerName = '';

    public string $payerType = 'individual';

    public string $reference = '';

    public string $note = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, BillLedger $ledger, TenantDirectory $tenants): void
    {
        $this->organizationId = $context->id();
        $this->authorize('create', Payment::class);
        $this->paidAt = PaymentDates::today()->toDateString();

        // ?bill= (a bill's page): its tenant too, when the member sees both.
        $bill = ctype_digit($this->billId) ? $ledger->balance((int) $this->billId) : null;

        if ($bill !== null && $this->billOffered($bill) && $tenants->narrowingAllows($this->user(), $bill->tenantProfileId)) {
            $this->tenantId = (string) $bill->tenantProfileId;
        } else {
            $this->billId = '';
        }

        if ($this->tenantId !== '' && (! ctype_digit($this->tenantId) || ! array_key_exists((int) $this->tenantId, $tenants->activeOptions($this->user())))) {
            $this->tenantId = '';
            $this->billId = '';
        }
    }

    /** Another tenant chosen: their bills, not the last one's. */
    public function updatedTenantId(): void
    {
        $this->billId = '';
    }

    #[BlockedWhileImpersonating]
    public function save(PaymentService $payments): void
    {
        $this->authorize('create', Payment::class);

        // Cash and POS: in the member's hands already, so confirmed at once (ONBOARDING_COMPLIANCE.md § 4.3).
        if (PaymentMethod::tryFrom($this->method)?->needsNote() === true) {
            $this->saveAndConfirm($payments);

            return;
        }

        $payment = $payments->record($this->user(), $this->input());
        session()->flash('payment-status', __('The payment was recorded. Confirm it once the money is in the account.'));

        $this->redirectRoute('workspace.payments.show', ['organization' => $this->organizationSlug(), 'payment' => $payment->id], navigate: true);
    }

    /** Record and confirm at once (cash in hand, a transfer already seen in the account). */
    #[BlockedWhileImpersonating]
    public function saveAndConfirm(PaymentService $payments): void
    {
        $this->authorize('create', Payment::class);
        $payment = $payments->recordAndConfirm($this->user(), $this->input());
        session()->flash('payment-status', $payment->method->needsNote()
            ? __('The payment was recorded and confirmed. Its receipt is on its way to the tenant.')
            : __('The payment was recorded and confirmed.'));

        $this->redirectRoute('workspace.payments.show', ['organization' => $this->organizationSlug(), 'payment' => $payment->id], navigate: true);
    }

    public function render(OrganizationContext $context, TenantDirectory $tenants, BillLedger $ledger): View
    {
        $user = $this->user();
        $bills = ctype_digit($this->tenantId) && $tenants->narrowingAllows($user, (int) $this->tenantId)
            ? array_values(array_filter($ledger->openBills((int) $this->tenantId), $this->billOffered(...)))
            : [];

        return view('payments::livewire.payment-form', [
            'organization' => $context->get(),
            'tenants' => $tenants->activeOptions($user),
            'bills' => $bills,
            'methods' => PaymentMethod::manual(),
            'payerTypes' => PayerType::cases(),
            'canConfirm' => $user->can('create', Payment::class),
        ]);
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'tenantId' => $this->tenantId,
            'billId' => $this->billId,
            'amount' => $this->amount,
            'method' => $this->method,
            'paidAt' => $this->paidAt,
            'payerName' => $this->payerName,
            'payerType' => $this->payerType,
            'reference' => $this->reference,
            'note' => $this->note,
        ];
    }

    /** A bill the member may record a payment for: not void, in their buildings. */
    private function billOffered(BillBalance $bill): bool
    {
        if ($bill->isVoid()) {
            return false;
        }

        $access = app(BuildingAccess::class);

        return $bill->propertyId === null ? $access->limitedTo($this->user()) === null : $access->allows($this->user(), $bill->propertyId);
    }

    private function organizationSlug(): string
    {
        return (string) app(OrganizationContext::class)->get()->slug;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
