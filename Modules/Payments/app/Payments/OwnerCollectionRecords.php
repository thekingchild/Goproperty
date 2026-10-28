<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use Carbon\CarbonImmutable;
use Modules\Payments\Contracts\CollectedAmount;
use Modules\Payments\Contracts\CollectedKind;
use Modules\Payments\Contracts\OwnerCollections;
use Modules\Payments\Enums\CreditReason;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Models\TenantCreditEntry;

/**
 * The Payments module's answers to Contracts\OwnerCollections (P08-05):
 * three reads through the organization scope (payments confirmed in the
 * range with their allocations, credit applied in the range, receipts
 * voided in the range with their payments' allocations). Reads only.
 */
final readonly class OwnerCollectionRecords implements OwnerCollections
{
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->setTimezone((string) config('app.timezone'));
        $to = $to->setTimezone((string) config('app.timezone'));
        $amounts = [];

        $confirmed = Payment::query()
            ->whereIn('status', [PaymentStatus::Confirmed, PaymentStatus::Refunded])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->get(['id', 'confirmed_at', 'receipt_id'])
            ->keyBy('id');

        $voided = Receipt::query()
            ->whereNotNull('voided_at')
            ->where('voided_at', '>=', $from)
            ->where('voided_at', '<', $to)
            ->get(['id', 'payment_id', 'number', 'voided_at']);

        $paymentIds = array_values(array_unique([...$confirmed->keys()->all(), ...$voided->pluck('payment_id')->all()]));
        $allocations = [];

        foreach (array_chunk($paymentIds, 1000) as $chunk) {
            foreach (PaymentAllocation::query()->whereIn('payment_id', $chunk)->orderBy('id')->get(['id', 'payment_id', 'bill_id', 'amount_kobo']) as $allocation) {
                $allocations[$allocation->payment_id][] = $allocation;
            }
        }

        $receiptNumbers = $confirmed->pluck('receipt_id')->filter()->isEmpty()
            ? []
            : Receipt::query()->whereIn('payment_id', $confirmed->keys()->all())->pluck('number', 'payment_id')->all();

        foreach ($confirmed as $payment) {
            foreach ($allocations[$payment->id] ?? [] as $allocation) {
                $amounts[] = new CollectedAmount(
                    billId: $allocation->bill_id,
                    amount: $allocation->amount_kobo,
                    at: $payment->confirmed_at ?? $from,
                    kind: CollectedKind::Payment,
                    paymentId: $payment->id,
                    receiptNumber: $receiptNumbers[$payment->id] ?? null,
                );
            }
        }

        foreach ($voided as $receipt) {
            foreach ($allocations[$receipt->payment_id] ?? [] as $allocation) {
                $amounts[] = new CollectedAmount(
                    billId: $allocation->bill_id,
                    amount: $allocation->amount_kobo->negate(),
                    at: $receipt->voided_at ?? $from,
                    kind: CollectedKind::ReceiptVoided,
                    paymentId: $receipt->payment_id,
                    receiptNumber: $receipt->number,
                );
            }
        }

        foreach (TenantCreditEntry::query()
            ->where('reason', CreditReason::AppliedToBill)
            ->whereNotNull('applied_bill_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('id')
            ->get(['id', 'applied_bill_id', 'amount_kobo', 'created_at']) as $entry) {
            $amounts[] = new CollectedAmount(
                billId: (int) $entry->applied_bill_id,
                amount: $entry->amount_kobo->abs(),
                at: $entry->created_at ?? $from,
                kind: CollectedKind::Credit,
                paymentId: null,
                receiptNumber: null,
            );
        }

        usort($amounts, static fn (CollectedAmount $a, CollectedAmount $b): int => [$a->at->getTimestamp(), $a->billId] <=> [$b->at->getTimestamp(), $b->billId]);

        return $amounts;
    }
}
