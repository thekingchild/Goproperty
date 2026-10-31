<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Media\Media;
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
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Payments\AllocationPlan;
use Modules\Payments\Payments\PartPaymentCounts;
use Modules\Payments\Payments\PaymentService;
use Modules\Payments\Proofs\ProofSubmissions;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * A payment, /o/{organization}/payments/{payment}: amount, how and when it
 * was paid, who recorded it, its status; while it waits, what confirming it
 * would settle (the bill it was for, then the tenant's open bills oldest
 * due first, the rest to tenant credit) with Confirm (and a note) and
 * Reject (with a reason); once confirmed, the bills it settled and the
 * credit it left, and its receipt (P03-10b). A payment recorded from a
 * proof of payment (P03-09) shows the proof for review: what the tenant
 * typed, who sent it, the file (PaymentProofPolicy::viewFile, through a
 * short-lived signed link), and a warning when the same file was uploaded
 * with another proof in the organization; members who may archive a proof
 * ("remove": the file is kept) do it here with a reason.
 * PaymentPolicy::confirm / ::reject, checked again by
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

    /** Why the proof is archived. */
    public string $archiveReason = '';

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

    /** "Remove" the payment's proof: archived with the reason; its file is kept (evidence). */
    #[BlockedWhileImpersonating]
    public function archiveProof(ProofSubmissions $proofs): void
    {
        $proof = PaymentProof::query()->where('payment_id', $this->payment()->id)->firstOrFail();
        $this->authorize('archive', $proof);

        $proofs->archive($this->user(), $proof, $this->archiveReason);
        $this->reset('archiveReason');
        $this->notice = __('The proof is archived. Its file is kept.');
    }

    public function render(OrganizationContext $context, PaymentRows $rows, PaymentService $payments, BillLedger $ledger, TenantCredit $credit, BuildingAccess $access, ProofSubmissions $proofs, TenantDirectory $tenants, PartPaymentCounts $partPayments): View
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
            'plan' => $plan = ($payment->status->isPending() ? $payments->plan($payment, $user) : null),
            'partPaymentNotes' => $this->partPaymentNotes($payment, $plan, $allocations, $partPayments),
            'canConfirm' => $canConfirm,
            'canReject' => $user->can('reject', $payment),
            'receipt' => $payment->receipt_id !== null ? Receipt::query()->find($payment->receipt_id) : null,
            'billLink' => static fn (?BillBalance $bill): bool => $bill !== null && ($bill->propertyId === null ? $access->limitedTo($user) === null : $access->allows($user, $bill->propertyId)),
        ] + $this->proofReview($payment, $user, $proofs, $tenants));
    }

    /**
     * Rent bills this payment takes past the number of payments their lease
     * allows (P03-17): flagged for the member, never refused (the money
     * reached the agency). Waiting: the bills confirming would settle;
     * confirmed: the bills it settled that now have too many payments.
     *
     * @param  array<int, array{amount: Money, bill: ?BillBalance, billId: int}>  $allocations
     * @return list<string>
     */
    private function partPaymentNotes(Payment $payment, ?AllocationPlan $plan, array $allocations, PartPaymentCounts $partPayments): array
    {
        $notes = [];

        if ($plan !== null) {
            foreach (array_keys($plan->allocations) as $billId) {
                $allowance = $partPayments->forBill($billId, leaving: $payment->id);

                if ($allowance !== null && $allowance->made + 1 > $allowance->limit) {
                    $notes[] = __('Over the agreed number of part payments: confirming makes this payment :count of :limit on bill :number.', ['count' => $allowance->made + 1, 'limit' => $allowance->limit, 'number' => $plan->bills[$billId]->number]);
                }
            }

            return $notes;
        }

        foreach ($allocations as $allocation) {
            $allowance = $partPayments->forBill($allocation['billId']);

            if ($allowance !== null && $allowance->isOver()) {
                $notes[] = __('Over the agreed number of part payments: bill :number has :count payments; its lease allows :limit.', ['count' => $allowance->made, 'limit' => $allowance->limit, 'number' => $allocation['bill']->number ?? '']);
            }
        }

        return $notes;
    }

    /**
     * The payment's proof for the reviewer: the proof, who sent it, the
     * file links the member may open, and the organization's other proofs
     * with the same file (each linked when the member may see its payment).
     *
     * @return array<string, mixed>
     */
    private function proofReview(Payment $payment, User $user, ProofSubmissions $proofs, TenantDirectory $tenants): array
    {
        $proof = PaymentProof::query()->where('payment_id', $payment->id)->first();

        if (! $proof instanceof PaymentProof || ! $user->can('view', $proof)) {
            return ['proof' => null];
        }

        $sameFile = $proofs->sameFile($proof);
        $names = $tenants->findMany(array_values(array_unique($sameFile->map(fn (PaymentProof $other): int => $other->tenant_profile_id)->all())));
        $payments = Payment::query()->withTrashed()->whereIn('id', $sameFile->map(fn (PaymentProof $other): int => $other->payment_id)->all())->get()->keyBy('id');
        $media = $proof->media_id !== null ? Media::query()->find($proof->media_id) : null;
        $media = $media instanceof Media ? $media : null;

        return [
            'proof' => $proof,
            'proofStatus' => $proof->status($payment),
            'proofSender' => $proof->submitted_by_user_id !== null ? User::query()->find($proof->submitted_by_user_id)?->name : null,
            'proofArchiver' => $proof->archived_by_user_id !== null ? User::query()->find($proof->archived_by_user_id)?->name : null,
            'proofIsImage' => str_starts_with((string) $media?->mime_type, 'image/'),
            'proofFileName' => $media?->name,
            'canViewProofFile' => $media !== null && $user->can('viewFile', $proof),
            'canArchiveProof' => $user->can('archive', $proof),
            'sameFile' => $sameFile->map(fn (PaymentProof $other): array => [
                'proof' => $other,
                'payment' => $payments->get($other->payment_id),
                'tenant' => isset($names[$other->tenant_profile_id]) ? $names[$other->tenant_profile_id]->name : __('Unknown tenant'),
                'link' => $tenants->narrowingAllows($user, $other->tenant_profile_id),
            ])->all(),
        ];
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
