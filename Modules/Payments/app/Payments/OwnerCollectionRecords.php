<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
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
        $timezone = (string) config('app.timezone');
        $from = $from->setTimezone($timezone);
        $to = $to->setTimezone($timezone);
        $amounts = [];

        // Plain rows, not models: a year of a large agency is ~12,000
        // payments and as many allocations (P09-03). The values are the
        // models' own: amounts in kobo, times in the app's timezone.
        $confirmed = Payment::query()
            ->whereIn('status', [PaymentStatus::Confirmed, PaymentStatus::Refunded])
            ->where('confirmed_at', '>=', $from)
            ->where('confirmed_at', '<', $to)
            ->toBase()
            ->get(['id', 'confirmed_at', 'receipt_id'])
            ->keyBy('id');

        $voided = Receipt::query()
            ->whereNotNull('voided_at')
            ->where('voided_at', '>=', $from)
            ->where('voided_at', '<', $to)
            ->toBase()
            ->get(['id', 'payment_id', 'number', 'voided_at']);

        $paymentIds = array_values(array_unique([...$confirmed->keys()->all(), ...$voided->pluck('payment_id')->all()]));
        $allocations = [];

        foreach (array_chunk($paymentIds, 1000) as $chunk) {
            foreach (PaymentAllocation::query()->whereIn('payment_id', $chunk)->orderBy('id')->toBase()->get(['id', 'payment_id', 'bill_id', 'amount_kobo']) as $allocation) {
                $allocations[(int) $allocation->payment_id][] = $allocation;
            }
        }

        $receiptNumbers = $confirmed->pluck('receipt_id')->filter()->isEmpty()
            ? []
            : Receipt::query()->whereIn('payment_id', $confirmed->keys()->all())->pluck('number', 'payment_id')->all();

        foreach ($confirmed as $payment) {
            $paymentId = (int) $payment->id;

            foreach ($allocations[$paymentId] ?? [] as $allocation) {
                $amounts[] = new CollectedAmount(
                    billId: (int) $allocation->bill_id,
                    amount: Money::kobo((int) $allocation->amount_kobo),
                    at: self::time($payment->confirmed_at, $timezone) ?? $from,
                    kind: CollectedKind::Payment,
                    paymentId: $paymentId,
                    receiptNumber: $receiptNumbers[$paymentId] ?? null,
                );
            }
        }

        foreach ($voided as $receipt) {
            foreach ($allocations[(int) $receipt->payment_id] ?? [] as $allocation) {
                $amounts[] = new CollectedAmount(
                    billId: (int) $allocation->bill_id,
                    amount: Money::kobo((int) $allocation->amount_kobo)->negate(),
                    at: self::time($receipt->voided_at, $timezone) ?? $from,
                    kind: CollectedKind::ReceiptVoided,
                    paymentId: (int) $receipt->payment_id,
                    receiptNumber: (string) $receipt->number,
                );
            }
        }

        foreach (TenantCreditEntry::query()
            ->where('reason', CreditReason::AppliedToBill)
            ->whereNotNull('applied_bill_id')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'applied_bill_id', 'amount_kobo', 'created_at']) as $entry) {
            $amounts[] = new CollectedAmount(
                billId: (int) $entry->applied_bill_id,
                amount: Money::kobo((int) $entry->amount_kobo)->abs(),
                at: self::time($entry->created_at, $timezone) ?? $from,
                kind: CollectedKind::Credit,
                paymentId: null,
                receiptNumber: null,
            );
        }

        usort($amounts, static fn (CollectedAmount $a, CollectedAmount $b): int => [$a->at->getTimestamp(), $a->billId] <=> [$b->at->getTimestamp(), $b->billId]);

        return $amounts;
    }

    /** A stored timestamp as the models read it: in the app's timezone. */
    private static function time(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $time = CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), $timezone);

        return $time instanceof CarbonImmutable ? $time : null;
    }
}
