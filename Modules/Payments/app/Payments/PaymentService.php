<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;
use Modules\Import\Contracts\ImportAction;
use Modules\Payments\Contracts\PayerType;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Contracts\RecordedPayment;
use Modules\Payments\Credit\TenantCredit;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Payments\Events\PaymentRecorded;
use Modules\Payments\Events\PaymentRejected;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\TenantCreditEntry;
use Modules\Payments\Receipts\Receipts;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The only writer of payments (AGENT.md § 3 rule 5; ARCHITECTURE.md § 6.4,
 * DATABASE_SCHEMA.md § 4). GoProperty records money the tenant paid into
 * the organization's own account or hands; it never moves any (rule 8).
 *
 * - record(): a member records a payment (bank transfer, cash, POS) for a
 *   tenant, optionally for one of their bills: `pending_confirmation`, in
 *   the confirmation queue. PaymentPolicy::create (`payments.confirm`).
 * - confirm(): allocates the payment across the tenant's bills and puts the
 *   rest to their credit, in ONE transaction (DB::transaction($cb, 3)):
 *   the payment's row is locked and re-read (a payment is confirmed once,
 *   whoever presses at the same time), then every bill it may settle is
 *   locked through Billing's Contracts\BillLedger::lock (id order) and paid
 *   with apply(), which re-checks under the lock: a bill is never settled
 *   beyond its amount and a void bill accepts nothing. Bills, in order: the
 *   bill the payment was for, then the tenant's other open bills, oldest
 *   due first — or an explicit split (bill id => amount) that must fit.
 *   Whatever is left goes to tenant credit (Credit\TenantCredit, under the
 *   tenant's credit row lock), never lost, never refused (DATABASE_SCHEMA.md
 *   integrity rule 2). PaymentPolicy::confirm.
 * - reject(): with a reason; nothing is allocated. PaymentPolicy::reject.
 * - recordProof(): the payment a proof of payment shows (P03-09), for
 *   Proofs\ProofSubmissions: waiting for confirmation, source `proof_upload`.
 * - recordDraft(): Contracts\PaymentRecorder's work (Collections,
 *   OnlinePayments): idempotent by key, optionally confirmed at once.
 * - importHistory() / rollBackImportedHistory(): the Payment history import
 *   sheet (P03-13b): a payment from before GoProperty, recorded confirmed
 *   with NO allocation and NO credit (the arrears and credit it left are the
 *   Opening balances sheet's), no receipt unless the row asks for one, and
 *   no tenant notice (source `import`); taken back by the import's rollback
 *   while it has no receipt.
 *
 * A member narrowed to some buildings (decision 9) works only on payments
 * of tenants they see, and their confirmations settle only bills of their
 * buildings (the rest goes to credit); the system (a verified Paystack
 * webhook) is not narrowed. Every change is audited under log `payments`
 * (`payment.recorded`, `payment.confirmed` with the split, `payment.rejected`
 * with the reason) and announced (PaymentRecorded — only for a payment left
 * waiting —, PaymentConfirmed, PaymentRejected) inside the transaction;
 * P03-10b issues the receipt from PaymentConfirmed in the same transaction
 * and tells the tenant once each commits. A confirmed payment is never deleted or edited (the
 * model refuses it too); undoing one is a reversal, not built yet.
 */
final readonly class PaymentService
{
    public const string LOG = 'payments';

    public function __construct(
        private BillLedger $ledger,
        private TenantDirectory $tenants,
        private BuildingAccess $access,
        private TenantCredit $credit,
        private PartPaymentCounts $partPayments,
    ) {}

    /**
     * Record a payment for the confirmation queue. Input as the record form
     * sends it: tenantId, billId (optional), amount (naira as typed),
     * method, paidAt (Y-m-d), payerName, payerType, reference, note.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function record(User $by, array $input): Payment
    {
        return $this->recordPayment($by, $input, announce: true);
    }

    /**
     * Record a payment and confirm it at once (cash in hand, a transfer the
     * member already sees in the account): one transaction, so a refused
     * confirmation records nothing either. The tenant hears of the
     * confirmation only (PaymentRecorded is not sent: it never waited).
     *
     * @param  array<string, mixed>  $input  as record()
     *
     * @throws ValidationException
     */
    public function recordAndConfirm(User $by, array $input, ?string $note = null): Payment
    {
        return $this->transaction(fn (): Payment => $this->confirm($by, $this->recordPayment($by, $input, announce: false), $note));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function recordPayment(User $by, array $input, bool $announce): Payment
    {
        Gate::forUser($by)->authorize('create', Payment::class);
        $values = $this->validatedInput($input);
        [$tenantId, $billId] = $this->checkParties($by, $values['tenantId'], $values['billId'], activeTenant: true);

        return $this->transaction(function () use ($by, $values, $tenantId, $billId, $announce): Payment {
            $payment = new Payment;
            $payment->tenant_profile_id = $tenantId;
            $payment->bill_id = $billId;
            $payment->amount_kobo = $values['amount'];
            $payment->method = $values['method'];
            $payment->source = PaymentSource::Manual;
            $payment->paid_at = $values['paidAt'];
            $payment->payer_name = $values['payerName'];
            $payment->payer_type = $values['payerType'];
            $payment->reference = $values['reference'];
            $payment->note = $values['note'];
            $payment->recorded_by_user_id = $by->id;
            $payment->save();

            $this->audited($payment, $by);

            if ($announce) {
                $this->announceRecorded($payment, $by);
            }

            return $payment;
        });
    }

    /**
     * The payment a proof of payment shows (P03-09; ONBOARDING_COMPLIANCE.md
     * § 4.1): a bank transfer waiting for confirmation, source
     * `proof_upload`, recorded by whoever submitted the proof. Only
     * Proofs\ProofSubmissions calls it, inside its transaction, once it has
     * checked who submits: a member uploading for a tenant ($member: their
     * PaymentPolicy::create and building narrowing apply, as for record()),
     * or the tenant themselves ($member null: PaymentProofPolicy::submitAsTenant
     * proved the bill is theirs through their portal account). The tenant
     * must not be archived; the bill, when given, must be theirs and not void.
     *
     * @throws ValidationException
     */
    public function recordProof(?User $member, User $submittedBy, int $tenantId, ?int $billId, Money $amount, CarbonImmutable $paidOn, ?string $payerName, ?string $reference, ?string $note): Payment
    {
        if ($member !== null) {
            Gate::forUser($member)->authorize('create', Payment::class);
        }

        if (! $amount->isPositive() || $amount->greaterThan(Money::naira(NairaInput::MAX_NAIRA))) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount above ₦0.')]);
        }

        [$tenantId, $billId] = $this->checkParties($member, $tenantId, $billId, activeTenant: true);

        return $this->transaction(function () use ($member, $submittedBy, $tenantId, $billId, $amount, $paidOn, $payerName, $reference, $note): Payment {
            if ($member === null && $billId !== null) {
                $this->refuseOverPartPayments($billId, $amount);
            }

            $payment = new Payment;
            $payment->tenant_profile_id = $tenantId;
            $payment->bill_id = $billId;
            $payment->amount_kobo = $amount;
            $payment->method = PaymentMethod::BankTransfer;
            $payment->source = PaymentSource::ProofUpload;
            $payment->paid_at = CarbonImmutable::parse($paidOn->toDateString());
            $payment->payer_name = self::text($payerName);
            $payment->payer_type = PayerType::Individual;
            $payment->reference = self::text($reference);
            $payment->note = self::text($note);
            $payment->recorded_by_user_id = $submittedBy->id;
            $payment->save();

            $this->audited($payment, $submittedBy);
            $this->announceRecorded($payment, $submittedBy);

            return $payment;
        });
    }

    /**
     * A tenant's own proof for a rent bill keeps to the number of payments
     * its lease allows (P03-17): refused once the bill has had them all
     * (confirmed or waiting), and the last one allowed must clear what the
     * bill owes beyond the payments waiting. Under the bill's lock, so two
     * proofs at once cannot both take the last place. Payments the agency
     * records or matches are never refused for it (the money reached them):
     * they are flagged when confirmed.
     *
     * @throws ValidationException
     */
    private function refuseOverPartPayments(int $billId, Money $amount): void
    {
        $bill = $this->ledger->lock([$billId])[$billId] ?? null;
        $allowance = $bill instanceof BillBalance ? $this->partPayments->forBalance($bill) : null;

        if ($allowance === null) {
            return;
        }

        if ($allowance->remaining() === 0) {
            throw ValidationException::withMessages(['amount' => trans_choice(
                'This bill is paid in one payment, which has been made or is waiting for the agency to confirm it. Contact the agency if you paid again.|This bill can be paid in at most :limit payments, and all :limit have been made or are waiting for the agency to confirm them. Contact the agency if you paid again.',
                $allowance->limit,
                ['limit' => $allowance->limit],
            )]);
        }

        $atLeast = $allowance->lastPaymentAtLeast();

        if ($allowance->remaining() === 1 && $amount->lessThan($atLeast)) {
            throw ValidationException::withMessages(['amount' => trans_choice(
                'This bill is paid in one payment, so it must be the full :amount.|This bill can be paid in at most :limit payments, so this one must be the full :amount still owed.',
                $allowance->limit,
                ['limit' => $allowance->limit, 'amount' => $atLeast->format()],
            )]);
        }
    }

    /**
     * Confirm a payment waiting for confirmation: allocate it (the bill it
     * was for, then the tenant's open bills oldest due first; or exactly
     * $allocations, bill id => amount), the rest to tenant credit.
     *
     * @param  array<int, Money>|null  $allocations
     *
     * @throws ValidationException
     */
    public function confirm(User $by, Payment $payment, ?string $note = null, ?array $allocations = null): Payment
    {
        Gate::forUser($by)->authorize('confirm', $payment);
        $note = self::text($note);
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:500']])->validate();

        return $this->transaction(fn (): Payment => $this->confirmLocked($by, $payment->id, $note, $allocations));
    }

    /**
     * Reject a payment waiting for confirmation (the money is not in the
     * account, a duplicate, a wrong amount), with the reason.
     *
     * @throws ValidationException
     */
    public function reject(User $by, Payment $payment, string $reason): Payment
    {
        Gate::forUser($by)->authorize('reject', $payment);
        $reason = (string) self::text($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the payment is rejected.'),
        ])->validate();

        return $this->transaction(function () use ($by, $payment, $reason): Payment {
            $payment = $this->lockedPending($payment->id);
            $payment->status = PaymentStatus::Rejected;
            $payment->rejected_at = CarbonImmutable::now();
            $payment->rejected_by_user_id = $by->id;
            $payment->rejection_reason = $reason;
            $payment->save();

            activity(self::LOG)
                ->event('payment.rejected')
                ->performedOn($payment)
                ->causedBy($by)
                ->withProperties([
                    'tenant_profile_id' => $payment->tenant_profile_id,
                    'amount_kobo' => $payment->amount_kobo->kobo,
                    'method' => $payment->method->value,
                    'reason' => $reason,
                ])
                ->log('Payment rejected');

            event(new PaymentRejected(
                paymentId: $payment->id,
                organizationId: $payment->organization_id,
                tenantProfileId: $payment->tenant_profile_id,
                amountKobo: $payment->amount_kobo->kobo,
                method: $payment->method->value,
                source: $payment->source->value,
                rejectedByUserId: $by->id,
                reason: $reason,
            ));

            return $payment;
        });
    }

    /**
     * What confirming the payment now would do, for the payment page (read
     * without locks: confirm() works it out again under them).
     */
    public function plan(Payment $payment, ?User $for): AllocationPlan
    {
        return $this->automaticPlan($payment, $for, lock: false);
    }

    /**
     * Contracts\PaymentRecorder's work: record the draft once per
     * idempotency key (a key used before gives back the earlier payment),
     * and with $confirm confirm it in the same transaction.
     *
     * @throws ValidationException
     */
    public function recordDraft(PaymentDraft $draft, bool $confirm): RecordedPayment
    {
        $by = $draft->by;

        if ($by !== null) {
            Gate::forUser($by)->authorize('create', Payment::class);
        }

        Validator::make([
            'idempotencyKey' => trim($draft->idempotencyKey),
            'amount' => $draft->amount->kobo,
            'payerName' => $draft->payerName,
            'reference' => $draft->reference,
            'note' => $draft->note,
            'paystackReference' => $draft->paystackReference,
        ], [
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'amount' => ['integer', 'min:1', 'max:'.(NairaInput::MAX_NAIRA * 100)],
            'payerName' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paystackReference' => ['nullable', 'string', 'max:100'],
        ])->validate();
        [$tenantId, $billId] = $this->checkParties($by, $draft->tenantProfileId, $draft->billId, activeTenant: false);

        return $this->transaction(function () use ($draft, $confirm, $by, $tenantId, $billId): RecordedPayment {
            $key = trim($draft->idempotencyKey);
            // A plain read first: a locking read of a key nobody used yet
            // would take a gap lock, and two calls racing would deadlock.
            $payment = $this->byKey($key, $draft->paystackReference, lock: false);
            $payment = $payment instanceof Payment ? Payment::query()->withTrashed()->whereKey($payment->id)->lockForUpdate()->first() : null;
            $created = false;

            if (! $payment instanceof Payment) {
                try {
                    $payment = DB::transaction(function () use ($draft, $by, $key, $tenantId, $billId): Payment {
                        $payment = new Payment;
                        $payment->tenant_profile_id = $tenantId;
                        $payment->bill_id = $billId;
                        $payment->amount_kobo = $draft->amount;
                        $payment->method = $draft->method;
                        $payment->source = $draft->source;
                        $payment->paid_at = CarbonImmutable::parse($draft->paidAt->setTimezone(PaymentDates::TIMEZONE)->toDateString());
                        $payment->payer_name = self::text($draft->payerName);
                        $payment->payer_type = $draft->payerType;
                        $payment->reference = self::text($draft->reference);
                        $payment->note = self::text($draft->note);
                        $payment->idempotency_key = $key;
                        $payment->paystack_reference = self::text($draft->paystackReference);
                        $payment->bank_statement_line_id = $draft->bankStatementLineId;
                        $payment->recorded_by_user_id = $by?->id;
                        $payment->save();

                        return $payment;
                    });
                    $created = true;
                    $this->audited($payment, $by);

                    if (! $confirm) {
                        $this->announceRecorded($payment, $by);
                    }
                } catch (UniqueConstraintViolationException $duplicate) {
                    // Recorded meanwhile by another call with the same key:
                    // its insert committed first, so this locking read sees it.
                    $payment = $this->byKey($key, $draft->paystackReference, lock: true) ?? throw $duplicate;
                }
            }

            if ($confirm && $payment->status->isPending()) {
                if ($by !== null) {
                    Gate::forUser($by)->authorize('confirm', $payment);
                }

                $payment = $this->confirmLocked($by, $payment->id, null, $draft->allocations);
            }

            return $this->recorded($payment, $created);
        });
    }

    /**
     * A payment from the Payment history import sheet (P03-13b; DATA_IMPORT.md
     * § 1 row 8: "for records and statements only; never re-issues receipts
     * unless asked"), inside the import's chunk transaction: a new confirmed
     * payment (source `import`, the batch's), one a rollback took back
     * brought back, or the one already on file (its receipt issued now when
     * the row asks and it has none). Settles no bill and adds no credit.
     * PaymentConfirmed carries `issueReceipt` from the row; the tenant is
     * never told (Listeners\TellTenantAboutPayment skips imports).
     *
     * @return array{0: ImportAction, 1: Payment}
     */
    public function importHistory(int $batchId, ImportedPayment $data, ?Payment $match): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Import payments inside the import\'s transaction.');
        }

        if ($match !== null) {
            $payment = Payment::query()->withTrashed()->whereKey($match->id)->lockForUpdate()->firstOrFail();
            $action = $payment->trashed() ? ImportAction::Restore : ImportAction::Update;

            if ($payment->trashed()) {
                $payment->restore();
                $payment->import_batch_id = $batchId;
                $payment->save();

                activity(self::LOG)
                    ->event('payment.import_restored')
                    ->performedOn($payment)
                    ->withProperties(['import_batch_id' => $batchId, 'tenant_profile_id' => $payment->tenant_profile_id, 'amount_kobo' => $payment->amount_kobo->kobo])
                    ->log('Imported payment brought back by an import');
            }

            if ($data->issueReceipt && $payment->receipt_id === null) {
                app(Receipts::class)->issue($payment->id);
                $payment->refresh();
            }

            return [$action, $payment];
        }

        $payment = new Payment;
        $payment->tenant_profile_id = $data->tenantProfileId;
        $payment->amount_kobo = $data->amount;
        $payment->method = $data->method;
        $payment->source = PaymentSource::Import;
        $payment->status = PaymentStatus::Confirmed;
        $payment->paid_at = CarbonImmutable::parse($data->paidAt->toDateString());
        $payment->reference = self::text($data->reference);
        $payment->note = self::text($data->note);
        $payment->confirmed_at = CarbonImmutable::now();
        $payment->import_batch_id = $batchId;
        $payment->external_ref = $data->externalRef;
        $payment->save();

        activity(self::LOG)
            ->event('payment.imported')
            ->performedOn($payment)
            ->withProperties([
                'import_batch_id' => $batchId,
                'tenant_profile_id' => $payment->tenant_profile_id,
                'amount_kobo' => $payment->amount_kobo->kobo,
                'method' => $payment->method->value,
                'paid_at' => $payment->paid_at->toDateString(),
                'issue_receipt' => $data->issueReceipt,
            ])
            ->log('Payment imported as history');

        event(new PaymentConfirmed(
            paymentId: $payment->id,
            organizationId: $payment->organization_id,
            tenantProfileId: $payment->tenant_profile_id,
            amountKobo: $payment->amount_kobo->kobo,
            method: $payment->method->value,
            source: $payment->source->value,
            confirmedByUserId: null,
            allocations: [],
            creditKobo: 0,
            issueReceipt: $data->issueReceipt,
        ));

        return [ImportAction::Create, $payment->refresh()];
    }

    /**
     * The import's rollback (P03-13b): soft deletes the batch's imported
     * history (DATA_IMPORT.md § 2 "Undo"), audited. Payments with a receipt
     * stay (the rollback is refused for them before: usedSince()).
     *
     * @return int how many were taken back
     */
    public function rollBackImportedHistory(int $batchId): int
    {
        $count = 0;

        foreach (Payment::query()->where('import_batch_id', $batchId)->where('source', PaymentSource::Import)->whereNull('receipt_id')->orderBy('id')->lockForUpdate()->get() as $payment) {
            $payment->delete();

            activity(self::LOG)
                ->event('payment.import_rolled_back')
                ->performedOn($payment)
                ->withProperties(['import_batch_id' => $batchId, 'tenant_profile_id' => $payment->tenant_profile_id, 'amount_kobo' => $payment->amount_kobo->kobo])
                ->log('Imported payment removed with its import');
            $count++;
        }

        return $count;
    }

    /** The payment as a RecordedPayment: its split and credit once confirmed. */
    public function recorded(Payment $payment, bool $created = false): RecordedPayment
    {
        $allocations = [];

        foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get() as $allocation) {
            $allocations[$allocation->bill_id] = $allocation->amount_kobo;
        }

        return new RecordedPayment(
            paymentId: $payment->id,
            status: $payment->status->value,
            created: $created,
            amount: $payment->amount_kobo,
            allocations: $allocations,
            credit: Money::sum(TenantCreditEntry::query()->where('payment_id', $payment->id)->get()->map(fn (TenantCreditEntry $entry): Money => $entry->amount_kobo)),
        );
    }

    /**
     * Inside the transaction: lock the payment, check it still waits,
     * allocate it, the rest to credit, mark it confirmed, audit, announce.
     *
     * @param  array<int, Money>|null  $allocations
     */
    private function confirmLocked(?User $by, int $paymentId, ?string $note, ?array $allocations): Payment
    {
        $payment = $this->lockedPending($paymentId);
        $plan = $allocations === null
            ? $this->automaticPlan($payment, $by, lock: true)
            : $this->explicitPlan($payment, $by, $allocations);

        $settled = [];

        foreach ($plan->allocations as $billId => $amount) {
            try {
                $balance = $this->ledger->apply($billId, $amount);
            } catch (BillNotPayable $refused) {
                // Not expected: the plan was made under the same locks.
                throw PaymentRefused::because($refused->getMessage());
            }

            $allocation = new PaymentAllocation;
            $allocation->payment_id = $payment->id;
            $allocation->bill_id = $billId;
            $allocation->amount_kobo = $amount;
            $allocation->save();
            $settled[] = ['bill_id' => $billId, 'number' => $balance->number, 'amount_kobo' => $amount->kobo, 'bill_status' => $balance->status];
        }

        if ($plan->credit->isPositive()) {
            $this->credit->addOverpayment($payment, $plan->credit, $by);
        }

        // Rent bills now past the number of payments their lease allows (P03-17): confirmed, and flagged.
        $over = [];

        foreach ($plan->allocations as $billId => $amount) {
            $allowance = $this->partPayments->forBill($billId);

            if ($allowance !== null && $allowance->isOver()) {
                $over[] = ['bill_id' => $billId, 'number' => $plan->bills[$billId]->number ?? null, 'payments' => $allowance->made, 'limit' => $allowance->limit];
            }
        }

        $payment->status = PaymentStatus::Confirmed;
        $payment->confirmed_at = CarbonImmutable::now();
        $payment->confirmed_by_user_id = $by?->id;
        $payment->confirmation_note = $note;
        $payment->save();

        $log = activity(self::LOG)
            ->event('payment.confirmed')
            ->performedOn($payment)
            ->withProperties([
                'tenant_profile_id' => $payment->tenant_profile_id,
                'amount_kobo' => $payment->amount_kobo->kobo,
                'method' => $payment->method->value,
                'source' => $payment->source->value,
                'allocations' => $settled,
                'credit_kobo' => $plan->credit->kobo,
                'note' => $note,
            ] + ($over === [] ? [] : ['over_part_payment_limit' => $over]));

        if ($by !== null) {
            $log->causedBy($by);
        }

        $log->log('Payment confirmed');

        event(new PaymentConfirmed(
            paymentId: $payment->id,
            organizationId: $payment->organization_id,
            tenantProfileId: $payment->tenant_profile_id,
            amountKobo: $payment->amount_kobo->kobo,
            method: $payment->method->value,
            source: $payment->source->value,
            confirmedByUserId: $by?->id,
            allocations: array_map(static fn (array $row): array => ['billId' => $row['bill_id'], 'amountKobo' => $row['amount_kobo']], $settled),
            creditKobo: $plan->credit->kobo,
        ));

        return $payment;
    }

    /**
     * The bill the payment was for, then the tenant's other open bills,
     * oldest due first (only those of the member's buildings), each up to
     * what it owes; the rest to credit. With $lock, the bills are locked
     * (Contracts\BillLedger::lock, id order) and the plan made from their
     * balances under the locks.
     */
    private function automaticPlan(Payment $payment, ?User $by, bool $lock): AllocationPlan
    {
        $open = array_values(array_filter(
            $this->ledger->openBills($payment->tenant_profile_id),
            fn (BillBalance $bill): bool => $this->billVisible($by, $bill),
        ));

        // The bill the payment was made for goes first; the others keep their order.
        usort($open, static fn (BillBalance $a, BillBalance $b): int => (int) ($b->id === $payment->bill_id) <=> (int) ($a->id === $payment->bill_id));
        $balances = $lock ? $this->ledger->lock(array_map(static fn (BillBalance $bill): int => $bill->id, $open)) : [];
        $remaining = $payment->amount_kobo;
        $allocations = [];
        $bills = [];

        foreach ($open as $bill) {
            $bill = $lock ? ($balances[$bill->id] ?? null) : $bill;

            if (! $remaining->isPositive()) {
                break;
            }

            if ($bill === null || $bill->isVoid() || ! $bill->outstanding->isPositive()) {
                continue;
            }

            $take = Money::min($remaining, $bill->outstanding);
            $allocations[$bill->id] = $take;
            $bills[$bill->id] = $bill;
            $remaining = $remaining->minus($take);
        }

        return new AllocationPlan($allocations, $remaining, $bills);
    }

    /**
     * An explicit split, checked under the bills' locks: each bill the
     * tenant's and the member's, not void, owing at least its share; the
     * shares positive and together no more than the payment.
     *
     * @param  array<int, Money>  $allocations
     */
    private function explicitPlan(Payment $payment, ?User $by, array $allocations): AllocationPlan
    {
        if ($allocations === []) {
            throw PaymentRefused::because(__('Say which bills the payment settles.'));
        }

        $total = Money::zero();

        foreach ($allocations as $amount) {
            if (! $amount->isPositive()) {
                throw PaymentRefused::because(__('Each amount for a bill must be more than ₦0.'));
            }

            $total = $total->plus($amount);
        }

        if ($total->greaterThan($payment->amount_kobo)) {
            throw PaymentRefused::because(__('The bills add up to :total, more than the payment of :amount.', ['total' => $total->format(), 'amount' => $payment->amount_kobo->format()]));
        }

        $ids = array_map('intval', array_keys($allocations));
        $balances = $this->ledger->lock($ids);
        $bills = [];

        foreach ($ids as $billId) {
            $bill = $balances[$billId] ?? null;

            if ($bill === null || $bill->tenantProfileId !== $payment->tenant_profile_id || ! $this->billVisible($by, $bill)) {
                throw PaymentRefused::because(__('One of the bills is not this tenant\'s.'));
            }

            if ($bill->isVoid()) {
                throw PaymentRefused::because(__('Bill :number is void and accepts no payment.', ['number' => $bill->number]));
            }

            if ($allocations[$billId]->greaterThan($bill->outstanding)) {
                throw PaymentRefused::because(__('Bill :number owes :owed; :amount is more than that.', ['number' => $bill->number, 'owed' => $bill->outstanding->format(), 'amount' => $allocations[$billId]->format()]));
            }

            $bills[$billId] = $bill;
        }

        return new AllocationPlan($allocations, $payment->amount_kobo->minus($total), $bills);
    }

    /** The payment, locked and re-read, while it waits for confirmation. */
    private function lockedPending(int $paymentId): Payment
    {
        $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();

        if (! $payment instanceof Payment) {
            throw PaymentRefused::because(__('No such payment.'));
        }

        return match ($payment->status) {
            PaymentStatus::PendingConfirmation => $payment,
            PaymentStatus::Confirmed => throw PaymentRefused::because(__('This payment is already confirmed.')),
            PaymentStatus::Rejected => throw PaymentRefused::because(__('This payment was rejected.')),
            PaymentStatus::Refunded => throw PaymentRefused::because(__('This payment was refunded.')),
        };
    }

    /** Whether the member's building narrowing lets them settle the bill (the system: always). */
    private function billVisible(?User $by, BillBalance $bill): bool
    {
        if ($by === null) {
            return true;
        }

        if ($bill->propertyId === null) {
            return $this->access->limitedTo($by) === null;
        }

        return $this->access->allows($by, $bill->propertyId);
    }

    /**
     * The tenant and bill of a new payment, checked: the tenant is the
     * organization's (not archived, for a member's record) and one the
     * member sees; the bill is the tenant's, not void, and in the member's
     * buildings.
     *
     * @return array{int, int|null}
     *
     * @throws ValidationException
     */
    private function checkParties(?User $by, int $tenantId, ?int $billId, bool $activeTenant): array
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null || ($activeTenant && $tenant->archived) || ($by !== null && ! $this->tenants->narrowingAllows($by, $tenantId))) {
            throw ValidationException::withMessages(['tenantId' => __('Choose a tenant.')]);
        }

        if ($billId === null) {
            return [$tenantId, null];
        }

        $bill = $this->ledger->balance($billId);

        if ($bill === null || $bill->tenantProfileId !== $tenantId || ! $this->billVisible($by, $bill)) {
            throw ValidationException::withMessages(['billId' => __('Choose one of the tenant\'s bills.')]);
        }

        if ($bill->isVoid()) {
            throw ValidationException::withMessages(['billId' => __('Bill :number is void and accepts no payment.', ['number' => $bill->number])]);
        }

        return [$tenantId, $billId];
    }

    /**
     * The record form's input, checked and typed.
     *
     * @param  array<string, mixed>  $input
     * @return array{tenantId: int, billId: int|null, amount: Money, method: PaymentMethod, paidAt: CarbonImmutable, payerName: string|null, payerType: PayerType, reference: string|null, note: string|null}
     *
     * @throws ValidationException
     */
    private function validatedInput(array $input): array
    {
        $id = static fn (mixed $value): ?string => is_int($value) ? (string) $value : self::text(is_string($value) ? $value : null);
        $values = [
            'tenantId' => $id($input['tenantId'] ?? null),
            'billId' => $id($input['billId'] ?? null),
            'amount' => self::text(is_string($input['amount'] ?? null) ? $input['amount'] : null),
            'method' => self::text(is_string($input['method'] ?? null) ? $input['method'] : null),
            'paidAt' => self::text(is_string($input['paidAt'] ?? null) ? $input['paidAt'] : null),
            'payerName' => self::text(is_string($input['payerName'] ?? null) ? $input['payerName'] : null),
            'payerType' => self::text(is_string($input['payerType'] ?? null) ? $input['payerType'] : null) ?? PayerType::Individual->value,
            'reference' => self::text(is_string($input['reference'] ?? null) ? $input['reference'] : null),
            'note' => self::text(is_string($input['note'] ?? null) ? $input['note'] : null),
        ];
        $method = PaymentMethod::tryFrom((string) $values['method']);
        $today = PaymentDates::today();

        Validator::make($values, [
            'tenantId' => ['required', 'integer', 'min:1'],
            'billId' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                $amount = is_string($value) ? NairaInput::parse($value) : null;

                if ($amount === null) {
                    $fail(__('Enter an amount in naira, such as 150,000.'));
                } elseif (! $amount->isPositive()) {
                    $fail(__('Enter an amount above ₦0.'));
                } elseif ($amount->greaterThan(Money::naira(NairaInput::MAX_NAIRA))) {
                    $fail(__('Enter an amount below ₦10,000,000,000.'));
                }
            }],
            'method' => ['required', Rule::in(array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::manual()))],
            'paidAt' => ['required', static function (string $attribute, mixed $value, Closure $fail) use ($today): void {
                $date = PaymentDates::parse(is_string($value) ? $value : null);

                if ($date === null) {
                    $fail(__('Enter a date.'));
                } elseif ($date->greaterThan($today)) {
                    $fail(__('The payment cannot be paid after today.'));
                }
            }],
            'payerName' => ['nullable', 'string', 'max:150'],
            'payerType' => ['required', Rule::in(PayerType::values())],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => [$method?->needsNote() === true ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'tenantId.required' => __('Choose a tenant.'),
            'method.required' => __('Choose how the tenant paid.'),
            'note.required' => __('Cash and POS payments need a note: who received it, where.'),
        ], [
            'tenantId' => 'tenant',
            'billId' => 'bill',
            'paidAt' => 'date paid',
            'payerName' => 'payer',
            'payerType' => 'payer type',
        ])->validate();

        assert($method instanceof PaymentMethod);

        return [
            'tenantId' => (int) $values['tenantId'],
            'billId' => $values['billId'] === null ? null : (int) $values['billId'],
            'amount' => NairaInput::parse((string) $values['amount']) ?? Money::zero(),
            'method' => $method,
            'paidAt' => PaymentDates::parse($values['paidAt']) ?? $today,
            'payerName' => $values['payerName'],
            'payerType' => PayerType::from($values['payerType']),
            'reference' => $values['reference'],
            'note' => $values['note'],
        ];
    }

    /** A payment recorded under this idempotency key (or Paystack reference), locked when asked. */
    private function byKey(string $key, ?string $paystackReference, bool $lock): ?Payment
    {
        $paystackReference = self::text($paystackReference);
        $query = Payment::query()->withTrashed()->where(function ($query) use ($key, $paystackReference): void {
            $query->where('idempotency_key', $key);

            if ($paystackReference !== null) {
                $query->orWhere('paystack_reference', $paystackReference);
            }
        })->orderBy('id');

        $payment = ($lock ? $query->lockForUpdate() : $query)->first();

        return $payment instanceof Payment ? $payment : null;
    }

    /** PaymentRecorded, inside the transaction (the tenant is told once it commits, P03-10b). */
    private function announceRecorded(Payment $payment, ?User $by): void
    {
        event(new PaymentRecorded(
            paymentId: $payment->id,
            organizationId: $payment->organization_id,
            tenantProfileId: $payment->tenant_profile_id,
            amountKobo: $payment->amount_kobo->kobo,
            method: $payment->method->value,
            source: $payment->source->value,
            recordedByUserId: $by?->id,
        ));
    }

    private function audited(Payment $payment, ?User $by): void
    {
        $log = activity(self::LOG)
            ->event('payment.recorded')
            ->performedOn($payment)
            ->withProperties([
                'tenant_profile_id' => $payment->tenant_profile_id,
                'bill_id' => $payment->bill_id,
                'amount_kobo' => $payment->amount_kobo->kobo,
                'method' => $payment->method->value,
                'source' => $payment->source->value,
                'paid_at' => $payment->paid_at->toDateString(),
            ]);

        if ($by !== null) {
            $log->causedBy($by);
        }

        $log->log('Payment recorded');
    }

    /** Trimmed text with runs of spaces made one; null when empty. */
    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
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
