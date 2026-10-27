<?php

declare(strict_types=1);

namespace Modules\Payments\Livewire;

use App\Documents\Verification\VerificationCodes;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Payments\Lists\ReceiptRows;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Receipts\Receipts;
use Modules\Payments\Receipts\ReceiptVoids;

/**
 * A receipt, /o/{organization}/receipts/{receipt} (P03-10b): number,
 * status, amount, tenant, the payment, what it was applied to and who
 * confirmed it, as issued; the receipt as printed (web page) and its PDFs
 * (the original, and once voided the VOID copy), when the member may see
 * them (ReceiptPolicy::viewDocument: not while staff impersonate).
 *
 * Voids need two people (P03 decision 4): "Ask to void" with a reason
 * (voidRequest), then another owner or administrator approves or declines
 * (voidApprove, voidDismiss; the requester may withdraw). In an
 * organization with a single owner and no administrator, that owner voids
 * alone with a reason (voidAlone, flagged single approver). Every action
 * checks its policy and goes through Receipts\ReceiptVoids, which checks
 * again under the receipt's lock; a refusal shows its reason and changes
 * nothing. The receipt id is locked and loaded through the organization
 * scope on every request (another organization's receipt answers 404).
 */
#[Layout('layouts::app')]
#[Title('Receipt')]
final class ReceiptShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $receiptId;

    #[Locked]
    public ?string $notice = null;

    /** Why the receipt should be voided. */
    public string $reason = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Receipt $receipt): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $receipt);
        $this->receiptId = $receipt->id;
    }

    #[BlockedWhileImpersonating]
    public function requestVoid(ReceiptVoids $voids): void
    {
        $receipt = $this->receipt();
        $this->authorize('voidRequest', $receipt);

        $voids->request($this->user(), $receipt, $this->reason);
        $this->reset('reason');
        $this->notice = __('Your request is sent. The receipt is voided once another owner or administrator approves it.');
    }

    #[BlockedWhileImpersonating]
    public function approveVoid(ReceiptVoids $voids): void
    {
        $receipt = $this->receipt();
        $this->authorize('voidApprove', $receipt);

        $voids->approve($this->user(), $receipt);
        $this->notice = __('The receipt is voided. A VOID copy is printed and the tenant is told.');
    }

    #[BlockedWhileImpersonating]
    public function dismissVoid(ReceiptVoids $voids): void
    {
        $receipt = $this->receipt();
        $this->authorize('voidDismiss', $receipt);

        $withdrawn = $receipt->void_requested_by_user_id === $this->user()->id;
        $voids->dismiss($this->user(), $receipt);
        $this->notice = $withdrawn ? __('Your request is withdrawn. The receipt stays valid.') : __('The request is declined. The receipt stays valid.');
    }

    #[BlockedWhileImpersonating]
    public function voidAlone(ReceiptVoids $voids): void
    {
        $receipt = $this->receipt();
        $this->authorize('voidAlone', $receipt);

        $voids->voidAlone($this->user(), $receipt, $this->reason);
        $this->reset('reason');
        $this->notice = __('The receipt is voided. A VOID copy is printed and the tenant is told.');
    }

    public function render(OrganizationContext $context, ReceiptRows $rows, Receipts $receipts): View
    {
        $receipt = $this->receipt();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $receipt);
        $user = $this->user();
        [$row] = $rows->rows([$receipt]);

        return view('payments::livewire.receipt-show', [
            'organization' => $context->get(),
            'receipt' => $receipt,
            'row' => $row,
            'printed' => $receipts->document($receipt),
            'verifyUrl' => VerificationCodes::url($receipt->verification_code),
            'code' => VerificationCodes::format($receipt->verification_code),
            'canSeeDocument' => $user->can('viewDocument', $receipt),
            'canSeePayment' => $user->can('view', $receipt->payment),
            'canRequest' => $user->can('voidRequest', $receipt),
            'canApprove' => $user->can('voidApprove', $receipt),
            'canDismiss' => $user->can('voidDismiss', $receipt),
            'canVoidAlone' => $user->can('voidAlone', $receipt),
            'ownRequest' => $receipt->void_requested_by_user_id === $user->id,
        ]);
    }

    private function receipt(): Receipt
    {
        return Receipt::query()->findOrFail($this->receiptId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
