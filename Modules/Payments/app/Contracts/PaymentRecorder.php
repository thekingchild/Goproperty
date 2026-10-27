<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * How other modules record tenant payments (ARCHITECTURE.md § 8 rule 3):
 * Collections' statement matching (P03-07b, `statement_match`) and
 * OnlinePayments' Paystack webhook (P03-08, `paystack_webhook`). Never the
 * `payments` table itself. Every payment still goes through the Payments
 * module's PaymentService (AGENT.md § 3 rule 5): the same allocation rules,
 * locks, audit and events (PaymentConfirmed) as a member's confirmation.
 *
 * Both calls are idempotent by PaymentDraft::$idempotencyKey (unique per
 * organization, also when two calls race): a key used before returns the
 * earlier payment unchanged (`created` false), except that
 * recordConfirmed() confirms an earlier payment still waiting for
 * confirmation. Each runs in its own transaction (or inside the caller's).
 *
 * Answers for the current organization only (OrganizationContext). Bound
 * in PaymentsServiceProvider.
 */
interface PaymentRecorder
{
    /**
     * Record a payment waiting for a member's confirmation (the
     * confirmation queue).
     *
     * @throws ValidationException when the draft is not a valid payment (unknown tenant or bill, amount not positive)
     * @throws AuthorizationException when the member in the draft may not record payments
     */
    public function record(PaymentDraft $draft): RecordedPayment;

    /**
     * Record a payment and confirm it at once (money already verified: a
     * Paystack transaction fetched from Paystack, a statement line a member
     * matched): allocated across the tenant's bills under their locks,
     * the rest to tenant credit, in one transaction.
     *
     * @throws ValidationException when the draft is not a valid payment, or an explicit allocation does not fit
     * @throws AuthorizationException when the member in the draft may not confirm payments
     */
    public function recordConfirmed(PaymentDraft $draft): RecordedPayment;
}
