<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\OnlinePayments\Transactions\PaystackTransactions;

/**
 * Settles an online payment when the payer comes back from Paystack's
 * checkout (the transaction's callback), in case the webhook is late or
 * never comes (ONBOARDING_COMPLIANCE.md § 4.2: "periodic verification of
 * pending references"). The same verify-by-fetch and the same once-only
 * settlement as the webhook (PaystackTransactions::settle), so whichever
 * gets there first records the payment and the other finds it settled.
 * Queued once per transaction at a time (ShouldBeUnique): a payer
 * reloading the return page does not ask Paystack again and again.
 */
final class SettlePaystackTransaction implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 45;

    /** Seconds the one-at-a-time lock lasts at most. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $reference,
    ) {
        $this->onQueue('billing');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->reference;
    }

    public function handle(OrganizationContext $context, PaystackTransactions $transactions): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization instanceof Organization) {
            $context->run($organization, fn () => $transactions->settle($this->reference));
        }
    }
}
