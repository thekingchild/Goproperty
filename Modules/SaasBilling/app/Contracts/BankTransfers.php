<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Paying a subscription invoice by bank transfer into GoProperty's own
 * account (P06-04; BILLING_PAYMENTS.md § 5), for Core's Settings →
 * Subscription page: the account to pay into, with the invoice number as
 * the narration, and the owner's proof of the transfer. A proof never marks
 * the invoice paid: it waits for GoProperty's staff to confirm it in the
 * console (BankTransferReviews). Works in the current organization. Bound
 * in SaasBillingServiceProvider.
 */
interface BankTransfers
{
    /** The proof's file: a screenshot, a photo or a PDF, at most 10 MB (media collection, evidence). */
    public const string COLLECTION = 'platform_payment_proofs';

    /** Proofs an organization may send in a day. */
    public const int PER_ORGANIZATION_PER_DAY = 5;

    /** GoProperty's account, or null while staff have not set it (then no invoice offers a transfer). */
    public function account(): ?TransferAccount;

    /**
     * Records the owner's proof of a transfer for an open subscription
     * invoice: a payment waiting for GoProperty's staff, with the file kept.
     * $input: amount (naira, e.g. "12,900"), transferredOn (Y-m-d),
     * senderName, senderBank (optional), narration (optional). Errors are
     * keyed by those names, `file` and `invoice`. Authorization is the
     * caller's (SubscriptionPolicy::pay).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function submit(User $owner, string $invoiceNumber, array $input, UploadedFile $file): void;
}
