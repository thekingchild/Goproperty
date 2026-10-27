<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Models\User;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Lists\PaymentRows;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\PaymentService;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * A payment, /o/{organization}/payments/{payment}: amount, how and when it
 * was paid, who recorded it, its status; while it waits, what confirming it
 * would settle (the bill it was for, then the tenant's open bills oldest
 * due first, the rest to tenant credit) with Confirm (and a note) and
 * Reject (with a reason); once confirmed, the bills it settled and the
 * credit it left. PaymentPolicy::confirm / ::reject, checked again by
 * Payments\PaymentService (a refusal shows its reason and changes
 * nothing); never while staff impersonate.
 *
 * The payment id is locked and the payment loaded through the organization
 * scope on every request, so another organization's payment (or, for an
 * assigned agent, another building's tenant's) answers 404. A payment has
 * no edit or delete: a confirmed one is history.
 */
#[Layout('layouts::app')]
#[Title('Payment')]
final class PaymentShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $paymentId;

    #[Locked]
    public ?string $notice = null;

    /** Confirmation note. */
    public string $note = '';

    /** Why the payment is rejected. */
    public string $reason = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Payment $payment): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $payment);
        $this->paymentId = $payment->id;
    }

    #[BlockedWhileImpersonating]
    public function confirm(PaymentService $payments): void
    {
        $payment = $this->payment();
        $this->authorize('confirm', $payment);

        $payments->confirm($this->user(), $payment, $this->note);
        $this->reset('note');
        $this->notice = __('The payment is confirmed.');
    }

    #[BlockedWhileImpersonating]
    public function reject(PaymentService $payments): void
    {
        $payment = $this->payment();
        $this->authorize('reject', $payment);

        $payments->reject($this->user(), $payment, $this->reason);
        $this->reset('reason');
        $this->notice = __('The payment is rejected. Nothing was applied to the tenant\'s bills.');
    }

    public function render(OrganizationContext $context, PaymentRows $rows, PaymentService $payments, BillLedger $ledger, TenantCredit $credit, BuildingAccess $access): View
    {
        $payment = $this->payment();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $payment);
        $user = $this->user();
        [$row] = $rows->rows([$payment]);
        $canConfirm = $user->can('confirm', $payment);
        $bill = $payment->bill_id !== null ? $ledger->balance($payment->bill_id) : null;

        $allocations = PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn (PaymentAllocation $allocation): array => ['amount' => $allocation->amount_kobo, 'bill' => $ledger->balance($allocation->bill_id), 'billId' => $allocation->bill_id])
            ->all();

        return view('payments::livewire.payment-show', [
            'organization' => $context->get(),
            'payment' => $payment,
            'row' => $row,
            'bill' => $bill,
            'allocations' => $allocations,
            'credited' => Money::sum(TenantCreditEntry::query()->where('payment_id', $payment->id)->get()->map(fn (TenantCreditEntry $entry): Money => $entry->amount_kobo)),
            'tenantCredit' => $credit->balance($payment->tenant_profile_id),
            'plan' => $payment->status->isPending() ? $payments->plan($payment, $user) : null,
            'canConfirm' => $canConfirm,
            'canReject' => $user->can('reject', $payment),
            'billLink' => static fn (?BillBalance $bill): bool => $bill !== null && ($bill->propertyId === null ? $access->limitedTo($user) === null : $access->allows($user, $bill->propertyId)),
        ]);
    }

    private function payment(): Payment
    {
        return Payment::query()->findOrFail($this->paymentId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
