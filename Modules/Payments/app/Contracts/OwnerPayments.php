<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

/**
 * How the owner portal (P03-16, the Properties module's /owner pages) reads
 * what was paid on the bills of an owner's buildings: never the Payments
 * module's models or tables (ARCHITECTURE.md § 8 rule 3). Read-only.
 *
 * Every method takes bill ids the portal proved belong to the signed-in
 * owner's buildings in the current organization (Billing's
 * Contracts\OwnerBills) and answers only about those bills: a receipt is
 * found only when it settled one of them, and shows only its part for
 * them. Payments waiting for confirmation, rejected ones and the proofs
 * tenants upload are never shown. Nothing here carries a payment
 * reference, the payer's or the organization's bank details (product owner
 * 2026-10-29: bank details and payment references stay hidden from
 * owners). Answers for the current organization only. No permission check:
 * the caller checks its policy. Bound in PaymentsServiceProvider.
 */
interface OwnerPayments
{
    /**
     * What was paid on these bills, newest first: each confirmed payment's
     * part of each bill (refunded ones too, marked) with its receipt, and
     * tenant credit used for them.
     *
     * @param  list<int>  $billIds
     * @return list<OwnerPayment>
     */
    public function forBills(array $billIds): array;

    /**
     * The ids of the bills the receipt's payment was applied to; empty for
     * an unknown receipt. Ids only: the caller keeps those of its owner's
     * buildings and asks receipt() with them.
     *
     * @return list<int>
     */
    public function billIdsOfReceipt(int $receiptId): array;

    /**
     * The receipt as the owner sees it, with only its lines for these
     * bills, or null when it settled none of them.
     *
     * @param  list<int>  $billIds
     */
    public function receipt(int $receiptId, array $billIds): ?OwnerReceipt;
}
