<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Billing\Contracts\BillNotPayable;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Models\Bill;

/**
 * Billing's answer to Contracts\BillLedger (read its comment): the bills'
 * paid amounts and the statuses that follow from them, under each bill's
 * row lock inside the caller's transaction.
 */
final readonly class Ledger implements BillLedger
{
    public function balance(int $billId): ?BillBalance
    {
        $bill = Bill::query()->find($billId);

        return $bill instanceof Bill ? self::toBalance($bill) : null;
    }

    public function findByReference(string $paymentReference): ?BillBalance
    {
        $reference = PaymentReferences::normalize($paymentReference);
        $bill = $reference === null ? null : Bill::query()->where('payment_reference', $reference)->first();

        return $bill instanceof Bill ? self::toBalance($bill) : null;
    }

    public function openBills(int $tenantProfileId): array
    {
        return array_values(Bill::query()
            ->where('tenant_profile_id', $tenantProfileId)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::PartiallyPaid, BillStatus::Overdue])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(self::toBalance(...))
            ->all());
    }

    public function lock(array $billIds): array
    {
        self::requireTransaction();
        $balances = [];

        if ($billIds === []) {
            return $balances;
        }

        // One locking read in primary-key order: callers locking the same
        // bills always take them in the same order.
        foreach (Bill::query()->whereKey(array_values(array_unique($billIds)))->orderBy('id')->lockForUpdate()->get() as $bill) {
            $balances[$bill->id] = self::toBalance($bill);
        }

        return $balances;
    }

    public function apply(int $billId, Money $amount): BillBalance
    {
        $bill = $this->lockedForChange($billId, $amount);

        if ($amount->greaterThan($bill->outstanding())) {
            throw new BillNotPayable(BillNotPayable::TOO_MUCH, __('Bill :number owes :owed; :amount is more than that.', ['number' => $bill->number, 'owed' => $bill->outstanding()->format(), 'amount' => $amount->format()]));
        }

        return $this->settle($bill, $bill->paid_kobo->plus($amount));
    }

    public function release(int $billId, Money $amount): BillBalance
    {
        $bill = $this->lockedForChange($billId, $amount);

        if ($amount->greaterThan($bill->paid_kobo)) {
            throw new BillNotPayable(BillNotPayable::TOO_MUCH, __('Only :paid was paid on bill :number.', ['number' => $bill->number, 'paid' => $bill->paid_kobo->format()]));
        }

        return $this->settle($bill, $bill->paid_kobo->minus($amount));
    }

    /** The bill, locked and re-read, if money may be applied to it or released from it. */
    private function lockedForChange(int $billId, Money $amount): Bill
    {
        self::requireTransaction();

        if (! $amount->isPositive()) {
            throw new BillNotPayable(BillNotPayable::NOT_POSITIVE, __('An amount applied to a bill must be more than nothing.'));
        }

        $bill = Bill::query()->whereKey($billId)->lockForUpdate()->first();

        if (! $bill instanceof Bill) {
            throw new BillNotPayable(BillNotPayable::UNKNOWN, __('No such bill.'));
        }

        if ($bill->status === BillStatus::Void) {
            throw new BillNotPayable(BillNotPayable::VOID, __('Bill :number is void and accepts no payment.', ['number' => $bill->number]));
        }

        return $bill;
    }

    private function settle(Bill $bill, Money $paid): BillBalance
    {
        $bill->paid_kobo = $paid;
        $bill->status = BillStatus::for($bill->amount_kobo, $paid, $bill->due_date->toDateString(), BillDates::today()->toDateString());
        $bill->save();

        return self::toBalance($bill);
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Bills are locked and paid inside a transaction.');
        }
    }

    private static function toBalance(Bill $bill): BillBalance
    {
        return new BillBalance(
            id: $bill->id,
            organizationId: $bill->organization_id,
            tenantProfileId: $bill->tenant_profile_id,
            leaseId: $bill->lease_id,
            propertyId: $bill->property_id,
            unitId: $bill->unit_id,
            type: $bill->type->value,
            number: $bill->number,
            paymentReference: $bill->payment_reference,
            status: $bill->status->value,
            amount: $bill->amount_kobo,
            paid: $bill->paid_kobo,
            outstanding: $bill->outstanding(),
            dueDate: $bill->due_date,
            periodStart: $bill->period_start,
            periodEnd: $bill->period_end,
        );
    }
}
