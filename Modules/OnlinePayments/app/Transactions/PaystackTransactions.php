<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Transactions;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\PayableBill;
use Modules\OnlinePayments\Connections\PaystackConnections;
use Modules\OnlinePayments\Enums\TransactionStatus;
use Modules\OnlinePayments\Enums\WebhookOutcome;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\Models\PaystackTransaction;
use Modules\OnlinePayments\Paystack\PaystackClient;
use Modules\OnlinePayments\Paystack\PaystackRefused;
use Modules\OnlinePayments\Paystack\PaystackUnavailable;
use Modules\OnlinePayments\Paystack\VerifiedTransaction;
use Modules\Payments\Contracts\PaymentDraft;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentRecorder;
use Modules\Payments\Contracts\PaymentSource;

/**
 * Tenants' online payments through the organization's own Paystack account
 * (ONBOARDING_COMPLIANCE.md § 4.2; ARCHITECTURE.md § 6.4; BILLING_PAYMENTS.md
 * § 4.2–4.3): the only writer of PaystackTransaction. GoProperty starts the
 * transaction with the organization's key and the money settles into the
 * organization's account (AGENT.md § 3 rule 8); GoProperty only records it.
 *
 * - offers()/start(): a bill is offered "Pay online" while the connection
 *   is active and what it still owes is positive and within the
 *   organization's online-payment cap (above it: bank details only).
 *   start() keeps the transaction (bill, amount asked) before asking
 *   Paystack to initialize it.
 * - settle(): after a webhook or the payer's return. Never trusts the
 *   webhook's own amounts: it asks Paystack (GET /transaction/verify) with
 *   the organization's secret key; nothing is recorded unless Paystack
 *   says the charge succeeded. Paid as asked (same amount, naira, the bill
 *   still owing at least that) → recorded and confirmed through the
 *   Payments module's PaymentRecorder (receipt issued there); otherwise
 *   held for review: a payment waiting for a member's confirmation with
 *   the reason in its note, or — money in another currency, which a kobo
 *   payment cannot express — no payment, just the held transaction shown
 *   on the Paystack settings page. Settled once (AGENT.md § 3 rule 6):
 *   under the transaction's row lock, a settled transaction is never
 *   settled again, and the recorder is idempotent by `paystack:{reference}`
 *   and unique per `(organization_id, paystack_reference)` as well.
 *
 * Works in the current organization (OrganizationContext). Suspended
 * organizations still record verified payments: the money is already in
 * their account (P03-10a's decision).
 */
final readonly class PaystackTransactions
{
    public const string AUDIT_LOG = 'online_payments';

    public function __construct(
        private PaystackConnections $connections,
        private PaystackClient $paystack,
        private BillLedger $ledger,
        private PaymentRecorder $recorder,
    ) {}

    /** Whether the bill may be paid online now: connected and active, owing something, within the cap. */
    public function offers(PayableBill|BillBalance $bill): bool
    {
        $connection = $this->connections->current();

        if (! $connection instanceof PaystackConnection || ! $connection->active()) {
            return false;
        }

        if ($bill instanceof BillBalance && $bill->isVoid()) {
            return false;
        }

        return $bill->outstanding->isPositive() && $bill->outstanding->lessThanOrEqual($this->connections->cap());
    }

    /**
     * Starts paying what the bill still owes online and returns Paystack's
     * checkout address. The payer's email is Paystack's requirement (its
     * receipt); GoProperty sends it to Paystack only and keeps no copy.
     *
     * @throws OnlinePaymentRefused
     */
    public function start(BillBalance $bill, string $email, string $callbackUrl, string $cancelUrl): string
    {
        $connection = $this->connections->current();

        if (! $connection instanceof PaystackConnection || ! $this->offers($bill)) {
            throw new OnlinePaymentRefused(__('This bill cannot be paid online. Pay by bank transfer with the details on the bill\'s page.'));
        }

        $transaction = new PaystackTransaction;
        $transaction->bill_id = $bill->id;
        $transaction->tenant_profile_id = $bill->tenantProfileId;
        $transaction->reference = $this->newReference();
        $transaction->amount_kobo = $bill->outstanding;
        $transaction->currency = 'NGN';
        $transaction->mode = $connection->mode ?? throw new OnlinePaymentRefused(__('This bill cannot be paid online now.'));
        $transaction->save();

        try {
            return $this->paystack->initialize((string) $connection->secret_key, [
                'email' => $email,
                'amount' => (string) $bill->outstanding->kobo,
                'currency' => 'NGN',
                'reference' => $transaction->reference,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'bill_number' => $bill->number,
                    'payment_reference' => $bill->paymentReference,
                    'cancel_action' => $cancelUrl,
                ],
            ]);
        } catch (PaystackRefused|PaystackUnavailable $problem) {
            $transaction->status = TransactionStatus::Failed;
            $transaction->hold_reason = mb_substr('Paystack did not start the payment: '.$problem->getMessage(), 0, 300);
            $transaction->save();

            throw new OnlinePaymentRefused(__('Paystack could not start the payment just now. Try again in a few minutes, or pay by bank transfer.'));
        }
    }

    /**
     * Settles the transaction with this reference from what Paystack says
     * about it now.
     *
     * @throws PaystackUnavailable when Paystack cannot be asked now (try again later; nothing was recorded)
     */
    public function settle(string $reference): WebhookOutcome
    {
        $transaction = PaystackTransaction::query()->where('reference', $reference)->first();

        if (! $transaction instanceof PaystackTransaction) {
            return WebhookOutcome::Ignored;
        }

        if ($transaction->status->isFinal()) {
            return WebhookOutcome::Duplicate;
        }

        $connection = $this->connections->current();

        if (! $connection instanceof PaystackConnection || ! $connection->connected()) {
            return WebhookOutcome::NotPaid;
        }

        // Verify by fetch, outside any lock (an HTTP call): only Paystack's answer counts.
        $verified = $this->paystack->verify((string) $connection->secret_key, $reference);

        if ($verified === null || $verified->reference !== $reference || ! $verified->succeeded()) {
            if ($verified !== null && $verified->reference === $reference && $verified->failed()) {
                $this->closeFailed($transaction->id, $verified);
            }

            return WebhookOutcome::NotPaid;
        }

        return DB::transaction(fn (): WebhookOutcome => $this->record($transaction->id, $verified), 3);
    }

    private function record(int $transactionId, VerifiedTransaction $verified): WebhookOutcome
    {
        $transaction = PaystackTransaction::query()->lockForUpdate()->findOrFail($transactionId);

        if ($transaction->status->isFinal()) {
            return WebhookOutcome::Duplicate;
        }

        $paid = Money::kobo($verified->amount);
        $transaction->paid_amount_kobo = $verified->amount > 0 ? $paid : null;
        $transaction->paid_currency = mb_substr($verified->currency, 0, 3);
        $transaction->channel = $verified->channel;
        $transaction->paid_at = $verified->paidAt ?? CarbonImmutable::now();
        $transaction->verified_at = CarbonImmutable::now();

        // Money the payment record cannot express: kept aside for the owner.
        if ($verified->currency !== 'NGN' || $verified->domain !== $transaction->mode->value || $verified->amount <= 0) {
            $reason = $verified->currency !== 'NGN'
                ? sprintf('Paid in %s, not naira: not recorded. Refund it from Paystack, or record it by hand.', $verified->currency)
                : sprintf('Paystack reports this payment in %s mode, not %s, or for no amount: not recorded.', $verified->domain, $transaction->mode->value);

            return $this->hold($transaction, $reason, null);
        }

        $bill = $this->ledger->balance($transaction->bill_id);
        $reason = match (true) {
            $bill === null => 'The bill this was paid for is gone.',
            ! $paid->equals($transaction->amount_kobo) => sprintf('%s was paid, but %s was asked for bill %s.', $paid->format(), $transaction->amount_kobo->format(), $bill->number),
            $bill->isVoid() => sprintf('Bill %s is void.', $bill->number),
            $bill->outstanding->lessThan($paid) => sprintf('Bill %s now owes %s, less than the %s paid.', $bill->number, $bill->outstanding->format(), $paid->format()),
            default => null,
        };
        $draft = fn (?string $note, ?int $billId): PaymentDraft => new PaymentDraft(
            tenantProfileId: $transaction->tenant_profile_id,
            amount: $paid,
            method: PaymentMethod::Paystack,
            source: PaymentSource::PaystackWebhook,
            idempotencyKey: 'paystack:'.$transaction->reference,
            paidAt: $transaction->paid_at ?? CarbonImmutable::now(),
            note: $note,
            billId: $billId,
            paystackReference: $transaction->reference,
            by: null,
        );

        try {
            if ($reason === null) {
                $recorded = $this->recorder->recordConfirmed($draft(null, $transaction->bill_id));
                $transaction->status = $recorded->isConfirmed() ? TransactionStatus::Confirmed : TransactionStatus::Held;
                $transaction->payment_id = $recorded->paymentId;
                $transaction->hold_reason = $recorded->isConfirmed() ? null : 'Recorded earlier and not confirmed.';
                $transaction->save();

                return $recorded->isConfirmed() ? WebhookOutcome::Confirmed : WebhookOutcome::Held;
            }

            $note = mb_substr(sprintf('Paid online through Paystack (%s). Held for review: %s', $transaction->reference, $reason), 0, 500);
            $recorded = $this->recorder->record($draft($note, $bill !== null && ! $bill->isVoid() ? $bill->id : null));

            return $this->hold($transaction, $reason, $recorded->paymentId);
        } catch (ValidationException $refused) {
            return $this->hold($transaction, mb_substr('Could not be recorded: '.implode(' ', $refused->validator->errors()->all()), 0, 300), null);
        }
    }

    private function hold(PaystackTransaction $transaction, string $reason, ?int $paymentId): WebhookOutcome
    {
        $transaction->status = TransactionStatus::Held;
        $transaction->hold_reason = mb_substr($reason, 0, 300);
        $transaction->payment_id = $paymentId;
        $transaction->save();

        activity(self::AUDIT_LOG)
            ->event('paystack.payment_held')
            ->performedOn($transaction)
            ->causedByAnonymous()
            ->withProperties([
                'reference' => $transaction->reference,
                'bill_id' => $transaction->bill_id,
                'asked_kobo' => $transaction->amount_kobo->kobo,
                'paid_kobo' => $transaction->paid_amount_kobo?->kobo,
                'paid_currency' => $transaction->paid_currency,
                'payment_id' => $paymentId,
                'reason' => $transaction->hold_reason,
            ])
            ->log('Online payment held for review');

        return WebhookOutcome::Held;
    }

    private function closeFailed(int $transactionId, VerifiedTransaction $verified): void
    {
        DB::transaction(function () use ($transactionId, $verified): void {
            $transaction = PaystackTransaction::query()->lockForUpdate()->findOrFail($transactionId);

            if ($transaction->status->isFinal()) {
                return;
            }

            $transaction->status = TransactionStatus::Failed;
            $transaction->hold_reason = mb_substr('Paystack reports the payment '.$verified->status.'.', 0, 300);
            $transaction->verified_at = CarbonImmutable::now();
            $transaction->save();
        }, 3);
    }

    /** "GPO-" and 20 hex digits: GoProperty's reference for the transaction at Paystack, unique everywhere. */
    private function newReference(): string
    {
        do {
            $reference = 'GPO-'.strtoupper(bin2hex(random_bytes(10)));
        } while (DB::table('paystack_transactions')->where('reference', $reference)->exists());

        return $reference;
    }
}
