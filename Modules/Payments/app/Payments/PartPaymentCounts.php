<?php

declare(strict_types=1);

namespace Modules\Payments\Payments;

use App\Support\Money;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Leasing\Contracts\RentPaymentLimits;
use Modules\Payments\Contracts\PartPaymentAllowance;
use Modules\Payments\Contracts\PartPayments;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;

/**
 * Counts a rent bill's payments against its lease's limit (P03-17;
 * Contracts\PartPayments). Made: the confirmed payments allocated to the
 * bill (each payment once, including the part of a payment that spilled
 * over from another bill). Waiting: payments still waiting for
 * confirmation that were made for the bill. Tenant credit applied to a new
 * bill is not a payment and does not count. Read in the current
 * organization; PaymentService calls it under the bill's lock when it
 * enforces the limit.
 */
final readonly class PartPaymentCounts implements PartPayments
{
    public function __construct(
        private BillLedger $ledger,
        private RentPaymentLimits $limits,
    ) {}

    public function forBill(int $billId, ?int $leaving = null): ?PartPaymentAllowance
    {
        $bill = $this->ledger->balance($billId);

        return $bill instanceof BillBalance ? $this->forBalance($bill, $leaving) : null;
    }

    /** As forBill(), from a balance already read (under a lock, when the caller holds one); $leaving: a payment not to count as waiting. */
    public function forBalance(BillBalance $bill, ?int $leaving = null): ?PartPaymentAllowance
    {
        if ($bill->type !== 'rent' || $bill->leaseId === null || $bill->isVoid()) {
            return null;
        }

        $limit = $this->limits->forLease($bill->leaseId);

        if ($limit === null) {
            return null;
        }

        $made = PaymentAllocation::query()->where('bill_id', $bill->id)->distinct()->count('payment_id');
        $waiting = Payment::query()
            ->where('bill_id', $bill->id)
            ->where('status', PaymentStatus::PendingConfirmation)
            ->when($leaving !== null, static fn ($query) => $query->whereKeyNot($leaving))
            ->get(['id', 'amount_kobo']);

        return new PartPaymentAllowance(
            billId: $bill->id,
            limit: $limit,
            made: $made,
            waiting: $waiting->count(),
            waitingAmount: Money::sum($waiting->map(static fn (Payment $payment): Money => $payment->amount_kobo)),
            outstanding: $bill->outstanding,
        );
    }
}
