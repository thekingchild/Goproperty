<?php

declare(strict_types=1);

namespace Modules\Payments\Credit;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Billing\Contracts\BillLedger;
use Modules\Payments\Enums\CreditReason;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\TenantCreditBalance;
use Modules\Payments\Models\TenantCreditEntry;

/**
 * Tenant credit (DATABASE_SCHEMA.md § 4 `tenant_credit_entries`,
 * ARCHITECTURE.md § 6.4): what a tenant paid beyond their bills, kept for
 * the next bill. The only writer of credit entries and balances.
 *
 * Every change locks the tenant's balance row (tenant_credit_balances,
 * SELECT … FOR UPDATE) inside the caller's transaction and writes an entry
 * beside it, so credit is never spent twice. Lock order, the same
 * everywhere: a payment's row, then bills (Billing's BillLedger::lock, in
 * id order), then the credit balance — so a confirmation and a new bill
 * using credit never deadlock each other.
 *
 * - addOverpayment(): + the part of a confirmed payment no open bill took.
 * - applyToBill(): on BillIssued (Listeners\ApplyCreditToNewBill), as much
 *   credit as the new bill still owes, settled through BillLedger::apply.
 *   Once per bill: the entry's `applied_bill_id` is unique, and the bill's
 *   row lock is taken before looking for an earlier entry.
 *
 * Current organization only (the models are organization-scoped).
 */
final readonly class TenantCredit
{
    public const string LOG = 'payments';

    public function __construct(private BillLedger $ledger) {}

    /** The tenant's credit now, read without a lock. */
    public function balance(int $tenantProfileId): Money
    {
        $balance = TenantCreditBalance::query()->where('tenant_profile_id', $tenantProfileId)->first();

        return $balance instanceof TenantCreditBalance ? $balance->balance_kobo : Money::zero();
    }

    /**
     * Put what a confirmed payment paid beyond the tenant's bills to their
     * credit, inside the confirming transaction.
     */
    public function addOverpayment(Payment $payment, Money $amount, ?User $by): TenantCreditEntry
    {
        self::requireTransaction();

        if (! $amount->isPositive()) {
            throw new LogicException('Only a positive overpayment goes to credit.');
        }

        $balance = $this->lockBalance($payment->tenant_profile_id);
        $entry = $this->entry($payment->tenant_profile_id, $amount, CreditReason::Overpayment, $by, paymentId: $payment->id);
        $balance->balance_kobo = $balance->balance_kobo->plus($amount);
        $balance->save();

        return $entry;
    }

    /**
     * Apply the tenant's credit to a newly issued bill, once: as much as the
     * bill owes, at most the credit held. Returns what was applied (null
     * when nothing was: no credit, the bill is void or settled, or credit
     * was applied to it before). Runs its own transaction.
     */
    public function applyToBill(int $billId): ?Money
    {
        return DB::transaction(function () use ($billId): ?Money {
            // The bill's row first (lock order), then everything else.
            $bill = $this->ledger->lock([$billId])[$billId] ?? null;

            if ($bill === null || $bill->isVoid() || ! $bill->outstanding->isPositive()) {
                return null;
            }

            if (TenantCreditEntry::query()->where('applied_bill_id', $billId)->exists()) {
                return null;
            }

            $balance = TenantCreditBalance::query()->where('tenant_profile_id', $bill->tenantProfileId)->lockForUpdate()->first();

            if (! $balance instanceof TenantCreditBalance || ! $balance->balance_kobo->isPositive()) {
                return null;
            }

            $applied = Money::min($balance->balance_kobo, $bill->outstanding);
            $after = $this->ledger->apply($billId, $applied);
            $entry = $this->entry($bill->tenantProfileId, $applied->negate(), CreditReason::AppliedToBill, null, billId: $billId, appliedBillId: $billId);
            $balance->balance_kobo = $balance->balance_kobo->minus($applied);
            $balance->save();

            activity(self::LOG)
                ->event('credit.applied')
                ->performedOn($entry)
                ->withProperties([
                    'tenant_profile_id' => $bill->tenantProfileId,
                    'bill_id' => $billId,
                    'bill_number' => $bill->number,
                    'amount_kobo' => $applied->kobo,
                    'credit_left_kobo' => $balance->balance_kobo->kobo,
                    'bill_status' => $after->status,
                ])
                ->log('Tenant credit applied to a new bill');

            return $applied;
        }, 3);
    }

    /**
     * The tenant's balance row, locked until the transaction ends (made on
     * first use: a second transaction making it at the same time waits on
     * the unique key, then takes the row the first one made).
     */
    private function lockBalance(int $tenantProfileId): TenantCreditBalance
    {
        if (! TenantCreditBalance::query()->where('tenant_profile_id', $tenantProfileId)->exists()) {
            try {
                DB::transaction(function () use ($tenantProfileId): void {
                    $balance = new TenantCreditBalance;
                    $balance->tenant_profile_id = $tenantProfileId;
                    $balance->save();
                });
            } catch (UniqueConstraintViolationException) {
                // Made meanwhile by another transaction: locked below.
            }
        }

        return TenantCreditBalance::query()->where('tenant_profile_id', $tenantProfileId)->lockForUpdate()->firstOrFail();
    }

    private function entry(int $tenantProfileId, Money $amount, CreditReason $reason, ?User $by, ?int $paymentId = null, ?int $billId = null, ?int $appliedBillId = null): TenantCreditEntry
    {
        $entry = new TenantCreditEntry;
        $entry->tenant_profile_id = $tenantProfileId;
        $entry->amount_kobo = $amount;
        $entry->reason = $reason;
        $entry->payment_id = $paymentId;
        $entry->bill_id = $billId;
        $entry->applied_bill_id = $appliedBillId;
        $entry->created_by_user_id = $by?->id;
        $entry->save();

        return $entry;
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Tenant credit changes inside a transaction.');
        }
    }
}
