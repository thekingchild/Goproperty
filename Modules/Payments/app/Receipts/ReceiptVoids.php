<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use App\Enums\OrganizationPermission;
use App\Members\Members;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Events\ReceiptVoided;
use Modules\Payments\Jobs\RenderReceipt;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Notifications\ReceiptVoidRequested;

/**
 * Voiding receipts (P03-10b; BRANDING_DOCUMENTS.md § 3; P03 decision 4):
 * the only writer of a receipt's void columns. A void needs two people:
 *
 * - request(): an owner, administrator or agent who may confirm payments
 *   (`payments.confirm`) asks, with a reason (3–500 characters); the other
 *   owners and administrators are told (ReceiptVoidRequested).
 * - approve(): a *different* member holding `receipts.approve_void`
 *   (owners and administrators) approves: the receipt is voided with the
 *   request's reason (`voided_by_user_id` = who asked,
 *   `void_approved_by_user_id` = who approved). The requester can never
 *   approve their own request, whatever their role.
 * - dismiss(): the requester withdraws the request, or an approver declines
 *   it; the receipt stays valid.
 * - voidAlone(): in an organization where exactly one member holds
 *   `receipts.approve_void` (a single owner and no administrator), that
 *   member voids with a typed reason, alone; flagged `void_single_approver`
 *   and audited as such.
 *
 * Each step checks ReceiptPolicy again (so the central guards apply: never
 * while staff impersonate, nothing in a suspended organization), locks the
 * receipt's row, re-reads it, and is audited under log `payments`
 * (`receipt.void_requested`, `receipt.void_dismissed`, `receipt.voided`).
 * A voided receipt is re-printed as a NEW kept file with the VOID watermark
 * and the reason (Jobs\RenderReceipt, `pdf` queue, after the commit); the
 * original PDF is kept. The payment itself is not changed: undoing a
 * payment is a reversal, not built yet.
 */
final readonly class ReceiptVoids
{
    public function __construct(
        private OrganizationContext $context,
        private Members $members,
    ) {}

    /**
     * Members who may approve a void here (`receipts.approve_void`).
     *
     * @return list<int>
     */
    public function approverIds(): array
    {
        return $this->members->holdersOf(OrganizationPermission::ApproveReceiptVoids, $this->context->get());
    }

    /** Whether the member is this organization's only approver (they may void alone). */
    public function isSoleApprover(User $user): bool
    {
        return $this->approverIds() === [$user->id];
    }

    /** @throws ValidationException */
    public function request(User $by, Receipt $receipt, string $reason): Receipt
    {
        Gate::forUser($by)->authorize('voidRequest', $receipt);
        $reason = $this->validReason($reason);

        $receipt = $this->transaction(function () use ($by, $receipt, $reason): Receipt {
            $receipt = $this->locked($receipt->id);

            if ($receipt->isVoided()) {
                throw ReceiptVoidRefused::because(__('This receipt is already voided.'));
            }

            if ($receipt->voidPending()) {
                throw ReceiptVoidRefused::because(__('A void of this receipt is already waiting for approval.'));
            }

            $receipt->void_requested_at = CarbonImmutable::now();
            $receipt->void_requested_by_user_id = $by->id;
            $receipt->void_request_reason = $reason;
            $receipt->save();

            activity(Receipts::LOG)
                ->event('receipt.void_requested')
                ->performedOn($receipt)
                ->causedBy($by)
                ->withProperties(['number' => $receipt->number, 'payment_id' => $receipt->payment_id, 'reason' => $reason])
                ->log('Receipt void requested');

            $this->tellApprovers($by, $receipt, $reason);

            return $receipt;
        });

        return $receipt;
    }

    /** @throws ValidationException */
    public function approve(User $by, Receipt $receipt): Receipt
    {
        Gate::forUser($by)->authorize('voidApprove', $receipt);

        return $this->transaction(function () use ($by, $receipt): Receipt {
            $receipt = $this->locked($receipt->id);

            if ($receipt->isVoided()) {
                throw ReceiptVoidRefused::because(__('This receipt is already voided.'));
            }

            if (! $receipt->voidPending() || $receipt->void_requested_by_user_id === null) {
                throw ReceiptVoidRefused::because(__('No void of this receipt is waiting for approval.'));
            }

            if ($receipt->void_requested_by_user_id === $by->id) {
                throw ReceiptVoidRefused::because(__('You asked for this void: another owner or administrator must approve it.'));
            }

            return $this->voided($receipt, $receipt->void_requested_by_user_id, $by, (string) $receipt->void_request_reason, singleApprover: false);
        });
    }

    /** @throws ValidationException */
    public function dismiss(User $by, Receipt $receipt): Receipt
    {
        Gate::forUser($by)->authorize('voidDismiss', $receipt);

        return $this->transaction(function () use ($by, $receipt): Receipt {
            $receipt = $this->locked($receipt->id);

            if (! $receipt->voidPending()) {
                throw ReceiptVoidRefused::because(__('No void of this receipt is waiting for approval.'));
            }

            $requestedBy = $receipt->void_requested_by_user_id;
            $reason = $receipt->void_request_reason;
            $receipt->void_requested_at = null;
            $receipt->void_requested_by_user_id = null;
            $receipt->void_request_reason = null;
            $receipt->save();

            activity(Receipts::LOG)
                ->event('receipt.void_dismissed')
                ->performedOn($receipt)
                ->causedBy($by)
                ->withProperties([
                    'number' => $receipt->number,
                    'requested_by_user_id' => $requestedBy,
                    'reason' => $reason,
                    'withdrawn' => $requestedBy === $by->id,
                ])
                ->log($requestedBy === $by->id ? 'Receipt void request withdrawn' : 'Receipt void request declined');

            return $receipt;
        });
    }

    /**
     * The single approver voids alone (decision 4): only while they are the
     * organization's one member holding `receipts.approve_void`.
     *
     * @throws ValidationException
     */
    public function voidAlone(User $by, Receipt $receipt, string $reason): Receipt
    {
        Gate::forUser($by)->authorize('voidAlone', $receipt);
        $reason = $this->validReason($reason);

        return $this->transaction(function () use ($by, $receipt, $reason): Receipt {
            // Serialised with membership changes (App\Members\Members locks the organization row too).
            Organization::query()->whereKey($this->context->id())->lockForUpdate()->first();
            $receipt = $this->locked($receipt->id);

            if ($receipt->isVoided()) {
                throw ReceiptVoidRefused::because(__('This receipt is already voided.'));
            }

            if (! $this->isSoleApprover($by)) {
                throw ReceiptVoidRefused::because(__('Another owner or administrator can approve voids here: ask for a void instead.'));
            }

            if ($receipt->voidPending() && $receipt->void_requested_by_user_id !== $by->id) {
                throw ReceiptVoidRefused::because(__('A member asked for this void: approve or decline their request.'));
            }

            return $this->voided($receipt, $by->id, $by, $reason, singleApprover: true);
        });
    }

    /** Inside the transaction: record the void, audit, announce, queue the VOID copy. */
    private function voided(Receipt $receipt, int $requestedBy, User $approvedBy, string $reason, bool $singleApprover): Receipt
    {
        $receipt->voided_at = CarbonImmutable::now();
        $receipt->voided_by_user_id = $requestedBy;
        $receipt->void_approved_by_user_id = $approvedBy->id;
        $receipt->void_reason = $reason;
        $receipt->void_single_approver = $singleApprover;
        $receipt->save();

        activity(Receipts::LOG)
            ->event('receipt.voided')
            ->performedOn($receipt)
            ->causedBy($approvedBy)
            ->withProperties([
                'number' => $receipt->number,
                'payment_id' => $receipt->payment_id,
                'amount_kobo' => $receipt->amount_kobo->kobo,
                'reason' => $reason,
                'requested_by_user_id' => $requestedBy,
                'approved_by_user_id' => $approvedBy->id,
                'single_approver' => $singleApprover,
            ])
            ->log($singleApprover ? 'Receipt voided by the single approver' : 'Receipt voided');

        event(new ReceiptVoided(
            receiptId: $receipt->id,
            paymentId: $receipt->payment_id,
            organizationId: $receipt->organization_id,
            tenantProfileId: $receipt->tenant_profile_id,
            number: $receipt->number,
            amountKobo: $receipt->amount_kobo->kobo,
            reason: $reason,
            requestedByUserId: $requestedBy,
            approvedByUserId: $approvedBy->id,
            singleApprover: $singleApprover,
        ));

        RenderReceipt::dispatch($receipt->organization_id, $receipt->id, true);

        return $receipt;
    }

    private function tellApprovers(User $by, Receipt $receipt, string $reason): void
    {
        $organization = $this->context->get();
        $approvers = User::query()->whereIn('id', array_values(array_diff($this->approverIds(), [$by->id])))->orderBy('id')->get();

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new ReceiptVoidRequested(
            receiptId: $receipt->id,
            number: $receipt->number,
            amount: $receipt->amount_kobo->format(),
            requestedBy: $by->name,
            reason: $reason,
            organizationName: $organization->name,
            url: route('workspace.receipts.show', ['organization' => $organization, 'receipt' => $receipt->id]),
        ));
    }

    private function locked(int $receiptId): Receipt
    {
        $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->first();

        if (! $receipt instanceof Receipt) {
            throw ReceiptVoidRefused::because(__('No such receipt.'));
        }

        return $receipt;
    }

    /** @throws ValidationException */
    private function validReason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the receipt should be voided.'),
        ])->validate();

        return $reason;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }
}
