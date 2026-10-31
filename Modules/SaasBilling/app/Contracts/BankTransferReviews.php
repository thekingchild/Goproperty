<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\PlatformStaff;

/**
 * Platform staff's review of a bank-transfer proof (P06-04;
 * BILLING_PAYMENTS.md § 5 steps 3–4), for the console: **confirm** (the
 * invoice's whole total reached GoProperty's account: the payment
 * confirmed, the invoice paid, a lapsed subscription active again by the
 * same rule as Paystack, the owners sent a receipt) or **reject** with a
 * reason (the invoice stays open; the owners are told and may send another
 * proof). Each is one audit entry with the staff member as causer, in the
 * same transaction. The caller checks `payments.confirm_transfer`; the
 * implementation checks it again. Payments are found by id across
 * organizations and handled inside their own.
 */
interface BankTransferReviews
{
    /** @throws TransferReviewRefused */
    public function confirm(PlatformStaff $staff, int $paymentId): void;

    /** @throws TransferReviewRefused */
    public function reject(PlatformStaff $staff, int $paymentId, string $reason): void;
}
