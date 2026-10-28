<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use LogicException;

/**
 * How the Payments module's Opening balances import sheet (P03-13b,
 * DATA_IMPORT.md § 1 row 6, § 3) makes a tenant's arrears a bill, without
 * reading or writing the `bills` table itself (ARCHITECTURE.md § 8 rule 3).
 *
 * - issue(): ONE bill of type `opening_balance` for what the tenant owed on
 *   the as-of date: due on that date, so a balance from before the import
 *   is overdue at once (arrears) and never billed again per period; issued
 *   through the same BillIssuer as every bill (number, payment reference,
 *   verification code, branding and parties kept, audited, BillIssued with
 *   `imported` set), so it can be paid, receipted and printed like any
 *   other. Inside the caller's transaction (the import's chunk).
 * - withdraw(): an import's rollback takes the bill back (soft delete,
 *   audited), refused (false) once anything was paid on it or it is void.
 * - restore(): the same balance imported again after a rollback brings the
 *   withdrawn bill back (its number and reference kept), as the new
 *   import's.
 *
 * Answers for the current organization only. Bound in BillingServiceProvider.
 */
interface OpeningBalanceBills
{
    /** @throws LogicException outside a transaction */
    public function issue(OpeningBalanceBill $bill): BillBalance;

    public function withdraw(int $billId): bool;

    public function restore(int $billId, int $importBatchId): ?BillBalance;
}
