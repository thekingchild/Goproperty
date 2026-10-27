<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use LogicException;

/**
 * The bills' money, for the Payments module (P03-10a: confirming a payment
 * allocates it across bills; tenant credit is applied to new bills) and
 * statement matching (P03-07b): never the `bills` table itself
 * (ARCHITECTURE.md § 8 rule 3).
 *
 * Built for concurrent confirmations: apply() and release() lock the
 * bill's row (SELECT … FOR UPDATE) and re-read it before checking and
 * writing, inside the caller's transaction, so two confirmations of the
 * same bill wait for each other and the second sees what the first
 * applied: a bill is never settled beyond its amount. They refuse to run
 * outside a transaction (LogicException), because the lock must last
 * until the caller's payment rows are written too. A caller allocating one
 * payment across several bills calls lock() first with every bill id: the
 * rows are locked in id order, so two payments covering the same bills
 * cannot deadlock each other, and the balances it returns are current for
 * the rest of the transaction. Run the transaction as
 * DB::transaction($callback, 3) (AGENT.md § 4).
 *
 * The bill's status follows its money (Enums\BillStatus): paid in full →
 * paid; else past its due date → overdue; else partially paid or unpaid.
 * A void bill accepts nothing (BillNotPayable). The caller audits its own
 * payment; the ledger records only the bill's paid amount.
 *
 * Answers for the current organization only (OrganizationContext). Bound
 * in BillingServiceProvider.
 */
interface BillLedger
{
    /** The bill's balance now, read without a lock (null when the organization has no such bill). */
    public function balance(int $billId): ?BillBalance;

    /** The bill whose payment reference this is, in the current organization (case and spaces ignored). */
    public function findByReference(string $paymentReference): ?BillBalance;

    /**
     * The tenant's bills that still ask for money (unpaid, partly paid or
     * overdue), oldest due date first: the order a payment pays them in.
     *
     * @return list<BillBalance>
     */
    public function openBills(int $tenantProfileId): array;

    /**
     * Lock the bills (in id order) until the caller's transaction ends and
     * return their current balances, keyed by id. Ids the organization does
     * not have are left out.
     *
     * @param  list<int>  $billIds
     * @return array<int, BillBalance>
     *
     * @throws LogicException outside a transaction
     */
    public function lock(array $billIds): array;

    /**
     * Settle $amount of the bill (part of a payment, or tenant credit).
     * Returns the balance afterwards.
     *
     * @throws BillNotPayable when the bill is void, unknown, or the amount is not positive or more than the bill still owes
     * @throws LogicException outside a transaction
     */
    public function apply(int $billId, Money $amount): BillBalance;

    /**
     * Take back $amount applied earlier (a confirmed payment reversed).
     * Returns the balance afterwards.
     *
     * @throws BillNotPayable when the bill is void, unknown, or the amount is not positive or more than was applied
     * @throws LogicException outside a transaction
     */
    public function release(int $billId, Money $amount): BillBalance;
}
