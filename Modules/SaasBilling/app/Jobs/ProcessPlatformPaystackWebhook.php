<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Jobs;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Modules\SaasBilling\Contracts\PlatformRefunds;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Payments\PlatformPaymentSettlement;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Throwable;

/**
 * Processes a delivery to GoProperty's own webhook (P05-03, P06-03;
 * BILLING_PAYMENTS.md § 4.2: kept first, processed on the queue), on
 * `billing`. Only deliveries whose signature matched, and only
 * `charge.success` and `charge.failed`, and the refund events (P06-07b:
 * the refunds being processed of the payment with that reference are
 * checked with Paystack, Refunds\PlatformRefunds::checkPayment()). For a
 * charge the payment with that reference is
 * found by one unscoped read of its `organization_id` (the
 * lookup-by-public-key pattern) and settled inside that organization
 * (Payments\PlatformPaymentSettlement, which verifies with Paystack first:
 * the event only says when to ask). SMS bundles and subscription invoices
 * alike. A reference GoProperty never issued is ignored. Safe to run again:
 * a processed delivery is left alone, unless staff replay it from the
 * console ($replay, `webhooks.replay`), and a settled payment is never
 * settled twice. When Paystack cannot be asked, retried with a backoff,
 * recording nothing.
 */
final class ProcessPlatformPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 45;

    public function __construct(public readonly int $webhookLogId, public readonly bool $replay = false)
    {
        $this->onQueue('billing');
        $this->afterCommit();
    }

    /** The events that mean "ask Paystack about this reference". */
    public const array EVENTS = ['charge.success', 'charge.failed'];

    /** Paystack's refund events: "ask Paystack about this payment's refunds". */
    public const array REFUND_EVENTS = ['refund.pending', 'refund.processing', 'refund.processed', 'refund.failed', 'refund.needs-attention'];

    public function handle(OrganizationContext $context, PlatformPaymentSettlement $settlement, PlatformRefunds $refunds): void
    {
        $log = DB::table('webhook_log')->where('id', $this->webhookLogId)->whereNull('organization_id')->where('provider', 'paystack')->first();

        if ($log === null || ! (bool) $log->signature_valid || ($log->processed_at !== null && ! $this->replay)) {
            return;
        }

        $reference = is_string($log->reference) ? $log->reference : null;

        if (in_array($log->event, self::REFUND_EVENTS, true) && $reference !== null) {
            try {
                $changed = $refunds->checkPayment($reference);
            } catch (PaystackUnavailable $unavailable) {
                DB::table('webhook_log')->where('id', $this->webhookLogId)->update(['processing_error' => mb_substr($unavailable->getMessage(), 0, 500), 'updated_at' => now()]);

                throw $unavailable;
            }

            $this->finish($changed > 0 ? PaymentOutcome::Refund : PaymentOutcome::Ignored);

            return;
        }

        $organization = $reference !== null ? PlatformPaymentSettlement::organizationFor($reference) : null;

        if (! in_array($log->event, self::EVENTS, true) || $reference === null || ! $organization instanceof Organization) {
            $this->finish(PaymentOutcome::Ignored);

            return;
        }

        try {
            $outcome = $context->run($organization, fn (): PaymentOutcome => $settlement->settle($reference));
        } catch (PaystackUnavailable $unavailable) {
            DB::table('webhook_log')->where('id', $this->webhookLogId)->update(['processing_error' => mb_substr($unavailable->getMessage(), 0, 500), 'updated_at' => now()]);

            throw $unavailable;
        }

        $this->finish($outcome);
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('webhook_log')->where('id', $this->webhookLogId)->whereNull('processed_at')
            ->update(['processing_error' => mb_substr('Gave up: '.($exception?->getMessage() ?? 'unknown error'), 0, 500), 'updated_at' => now()]);
    }

    private function finish(PaymentOutcome $outcome): void
    {
        DB::table('webhook_log')->where('id', $this->webhookLogId)->update([
            'processed_at' => CarbonImmutable::now(),
            'outcome' => $outcome->value,
            'processing_error' => null,
            'updated_at' => now(),
        ]);
    }
}
