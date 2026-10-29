<?php

declare(strict_types=1);

namespace Modules\Payments\Receipts;

use Carbon\CarbonImmutable;
use Modules\Payments\Contracts\OwnerPayment;
use Modules\Payments\Contracts\OwnerPayments;
use Modules\Payments\Contracts\OwnerReceipt;
use Modules\Payments\Contracts\OwnerReceiptLine;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Enums\CreditReason;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\ReceiptStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Models\TenantCreditEntry;

/**
 * The Payments module's answers to Contracts\OwnerPayments (the owner
 * portal, P03-16), read through the organization scope and only about the
 * given bills: confirmed payments' allocations to them (refunded ones too),
 * tenant credit used for them, and their receipts as kept at issue
 * (`receipts.details`, read back by key). The payment reference kept on a
 * receipt is never read out. Reads only.
 */
final readonly class OwnerPaymentRecords implements OwnerPayments
{
    public function forBills(array $billIds): array
    {
        $billIds = array_values(array_unique($billIds));

        if ($billIds === []) {
            return [];
        }

        $allocations = [];

        foreach (array_chunk($billIds, 1000) as $chunk) {
            foreach (PaymentAllocation::query()->whereIn('bill_id', $chunk)->orderBy('id')->get(['id', 'payment_id', 'bill_id', 'amount_kobo']) as $allocation) {
                $allocations[] = $allocation;
            }
        }

        $paymentIds = array_values(array_unique(array_map(static fn (PaymentAllocation $allocation): int => $allocation->payment_id, $allocations)));
        $payments = [];
        $receipts = [];

        foreach (array_chunk($paymentIds, 1000) as $chunk) {
            foreach (Payment::query()->withTrashed()->whereKey($chunk)->whereIn('status', [PaymentStatus::Confirmed, PaymentStatus::Refunded])->get() as $payment) {
                $payments[$payment->id] = $payment;
            }

            foreach (Receipt::query()->whereIn('payment_id', $chunk)->get(['id', 'payment_id', 'number', 'voided_at']) as $receipt) {
                $receipts[$receipt->payment_id] = $receipt;
            }
        }

        $paid = [];

        foreach ($allocations as $allocation) {
            $payment = $payments[$allocation->payment_id] ?? null;

            if (! $payment instanceof Payment) {
                continue;
            }

            $receipt = $receipts[$payment->id] ?? null;
            $paid[] = new OwnerPayment(
                kind: OwnerPayment::PAYMENT,
                billId: $allocation->bill_id,
                amount: $allocation->amount_kobo,
                at: $payment->confirmed_at ?? $payment->paid_at,
                paidOn: $payment->paid_at,
                method: $payment->method->value,
                methodLabel: $payment->method->label(),
                receiptId: $receipt?->id,
                receiptNumber: $receipt?->number,
                receiptVoided: $receipt !== null && $receipt->voided_at !== null,
                refunded: $payment->status === PaymentStatus::Refunded,
            );
        }

        foreach (array_chunk($billIds, 1000) as $chunk) {
            foreach (TenantCreditEntry::query()
                ->where('reason', CreditReason::AppliedToBill)
                ->whereIn('applied_bill_id', $chunk)
                ->orderBy('id')
                ->get(['id', 'applied_bill_id', 'amount_kobo', 'created_at']) as $entry) {
                $paid[] = new OwnerPayment(
                    kind: OwnerPayment::CREDIT,
                    billId: (int) $entry->applied_bill_id,
                    amount: $entry->amount_kobo->abs(),
                    at: $entry->created_at ?? CarbonImmutable::now(),
                    paidOn: null,
                    method: null,
                    methodLabel: 'Tenant\'s credit',
                    receiptId: null,
                    receiptNumber: null,
                    receiptVoided: false,
                    refunded: false,
                );
            }
        }

        usort($paid, static fn (OwnerPayment $a, OwnerPayment $b): int => [$b->at->getTimestamp(), $b->billId] <=> [$a->at->getTimestamp(), $a->billId]);

        return $paid;
    }

    public function billIdsOfReceipt(int $receiptId): array
    {
        $paymentId = Receipt::query()->whereKey($receiptId)->value('payment_id');

        if ($paymentId === null) {
            return [];
        }

        return array_values(array_unique(array_map('intval', PaymentAllocation::query()->where('payment_id', $paymentId)->orderBy('id')->pluck('bill_id')->all())));
    }

    public function receipt(int $receiptId, array $billIds): ?OwnerReceipt
    {
        if ($billIds === []) {
            return null;
        }

        $receipt = Receipt::query()->whereKey($receiptId)->first();

        if (! $receipt instanceof Receipt) {
            return null;
        }

        $wanted = array_flip(array_map('intval', $billIds));
        $issued = [];

        foreach (is_array($receipt->details['allocations'] ?? null) ? $receipt->details['allocations'] : [] as $row) {
            $billId = is_array($row) ? ($row['bill_id'] ?? null) : null;

            if (is_int($billId) || (is_string($billId) && ctype_digit($billId))) {
                $issued[(int) $billId] = $row;
            }
        }

        $lines = [];

        foreach (PaymentAllocation::query()->where('payment_id', $receipt->payment_id)->orderBy('id')->get(['id', 'bill_id', 'amount_kobo']) as $allocation) {
            if (! isset($wanted[$allocation->bill_id])) {
                continue;
            }

            $row = $issued[$allocation->bill_id] ?? [];
            $lines[] = new OwnerReceiptLine(
                billId: $allocation->bill_id,
                billNumber: self::text($row['invoice_number'] ?? null),
                description: self::text($row['description'] ?? null),
                amount: $allocation->amount_kobo,
                settled: (bool) ($row['settled'] ?? false),
            );
        }

        if ($lines === []) {
            return null;
        }

        $payment = is_array($receipt->details['payment'] ?? null) ? $receipt->details['payment'] : [];
        $method = PaymentMethod::tryFrom(self::text($payment['method'] ?? null))
            ?? Payment::query()->withTrashed()->whereKey($receipt->payment_id)->first()->method ?? PaymentMethod::BankTransfer;
        $paidOn = self::text($payment['paid_on'] ?? null);
        $status = $receipt->isVoided() ? ReceiptStatus::Voided : ReceiptStatus::Valid;

        return new OwnerReceipt(
            id: $receipt->id,
            organizationId: $receipt->organization_id,
            number: $receipt->number,
            issuedAt: $receipt->issued_at,
            amount: $receipt->amount_kobo,
            paidOn: $paidOn !== '' ? CarbonImmutable::parse($paidOn) : null,
            method: $method->value,
            methodLabel: $method->label(),
            status: $status->value,
            statusLabel: $status->label(),
            statusColor: $status->color(),
            voidedAt: $receipt->voided_at,
            lines: $lines,
        );
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
