<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A payment another module records through Contracts\PaymentRecorder:
 * plain values. GoProperty only records money the tenant paid into the
 * organization's own account (AGENT.md § 3 rule 8).
 *
 * - `idempotencyKey`: what makes the record unique in the organization (a
 *   Paystack reference, a bank statement line), so a replayed webhook or a
 *   line matched twice gives back the first payment (AGENT.md § 3 rule 6).
 *   At most 120 characters; prefix it with the source, e.g.
 *   "paystack:T123", "statement-line:42".
 * - `billId`: the bill the money was for (a reference in a narration, the
 *   bill paid online); confirming settles it first, then the tenant's other
 *   open bills, oldest due first, then tenant credit.
 * - `allocations`: an explicit split instead (bill id => amount), e.g. a
 *   member splitting one statement credit across bills (P03-07b); each
 *   amount must fit the bill and the total the payment; the rest goes to
 *   tenant credit.
 * - `by`: the member acting (their permissions and building narrowing are
 *   checked: `payments.confirm`, never while staff impersonate), or null
 *   for the system (a verified Paystack webhook).
 */
final readonly class PaymentDraft
{
    /**
     * @param  array<int, Money>|null  $allocations  bill id => amount
     */
    public function __construct(
        public int $tenantProfileId,
        public Money $amount,
        public PaymentMethod $method,
        public PaymentSource $source,
        public string $idempotencyKey,
        public CarbonImmutable $paidAt,
        public ?string $payerName = null,
        public PayerType $payerType = PayerType::Individual,
        public ?string $reference = null,
        public ?string $note = null,
        public ?int $billId = null,
        public ?array $allocations = null,
        public ?int $bankStatementLineId = null,
        public ?string $paystackReference = null,
        public ?User $by = null,
    ) {}
}
