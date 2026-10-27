<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\OnlinePayments\Enums\WebhookOutcome;
use Modules\OnlinePayments\Models\PaystackWebhookLog;
use Modules\OnlinePayments\Paystack\PaystackUnavailable;
use Modules\OnlinePayments\Transactions\PaystackTransactions;
use Throwable;

/**
 * Processes a delivery the webhook kept (BILLING_PAYMENTS.md § 4.2: "persist
 * … before processing"; ARCHITECTURE.md § 10: 2xx fast-ack, then process on
 * the queue), on `billing` (the payments lane of the shared-hosting tick),
 * inside the delivery's organization. Only deliveries whose signature
 * matched are processed, and only `charge.success` acts: the transaction
 * it names is settled (Transactions\PaystackTransactions::settle, which
 * verifies with Paystack before anything is recorded). Safe to run again
 * and to replay (AGENT.md § 3 rule 6): a processed delivery is left alone,
 * and a settled transaction is never settled twice. When Paystack cannot
 * be asked, the job is retried with a backoff, recording nothing.
 */
final class ProcessPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 45;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $webhookLogId,
    ) {
        $this->onQueue('billing');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, PaystackTransactions $transactions): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $context->run($organization, function () use ($transactions): void {
            $log = PaystackWebhookLog::query()->find($this->webhookLogId);

            if (! $log instanceof PaystackWebhookLog || ! $log->signature_valid || $log->processed_at !== null) {
                return;
            }

            if ($log->event !== 'charge.success' || $log->reference === null) {
                $this->finish($log, WebhookOutcome::Ignored);

                return;
            }

            try {
                $outcome = $transactions->settle($log->reference);
            } catch (PaystackUnavailable $unavailable) {
                $log->processing_error = mb_substr($unavailable->getMessage(), 0, 500);
                $log->save();

                throw $unavailable;
            }

            $this->finish($log, $outcome);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        app(OrganizationContext::class)->run($organization, function () use ($exception): void {
            $log = PaystackWebhookLog::query()->find($this->webhookLogId);

            if ($log instanceof PaystackWebhookLog && $log->processed_at === null) {
                $log->processing_error = mb_substr('Gave up: '.($exception?->getMessage() ?? 'unknown error'), 0, 500);
                $log->save();
            }
        });
    }

    private function finish(PaystackWebhookLog $log, WebhookOutcome $outcome): void
    {
        $log->processed_at = CarbonImmutable::now();
        $log->outcome = $outcome;
        $log->processing_error = null;
        $log->save();
    }
}
