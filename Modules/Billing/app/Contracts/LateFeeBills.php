<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Support\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * How the LateFees module (PL-02, docs/plans/late-fees-2026-11-11.md)
 * charges a late fee on overdue rent without reading or writing the
 * `bills` table itself (ARCHITECTURE.md § 8 rule 3).
 *
 * - rentBillsDue(): the organization's rent bills that still ask for money
 *   and were due on or before $dueBy, never imported (an imported opening
 *   balance or rent history gets no fee), by id after $afterId, at most
 *   $limit, read without a lock. The caller locks one with
 *   BillLedger::lock() before charging it.
 * - issue(): ONE bill of type `late_fee` for that rent bill's tenant,
 *   lease, building and unit, due today (Lagos), with one line "Late fee"
 *   and $note; issued through the same BillIssuer as every bill (number,
 *   payment reference, verification code, branding and parties kept,
 *   audited, BillIssued). Inside the caller's transaction, with the rent
 *   bill locked; the caller keeps the record that stops a second fee.
 *
 * Answers for the current organization only. Bound in BillingServiceProvider.
 */
interface LateFeeBills
{
    /** @return list<BillBalance> */
    public function rentBillsDue(CarbonImmutable $dueBy, int $afterId, int $limit): array;

    /**
     * @throws LogicException outside a transaction, or for a bill that is not one of the organization's rent bills
     */
    public function issue(int $rentBillId, Money $fee, string $note): BillBalance;
}
