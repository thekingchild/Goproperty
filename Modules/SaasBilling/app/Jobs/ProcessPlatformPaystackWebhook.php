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
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\SmsCredits\SmsCreditPurchases;
use Throwable;

/**
 * Processes a delivery to GoProperty's own webhook (P05-03;
 * BILLING_PAYMENTS.md § 4.2: kept first, processed on the queue), on
 * `billing`. Only deliveries whose signature matched, and only
 * `charge.success`: the payment with that reference is found by one
 * unscoped read of its `organization_id` (the lookup-by-public-key pattern)
 * and settled inside that organization (SmsCreditPurchases::settle, which
 * verifies with Paystack first). A reference GoProperty never issued is
 * ignored. Safe to run again and to replay: a processed delivery is left
 * alone and a settled payment is never settled twice. When Paystack cannot
 * be asked, retried with a backoff, recording nothing.
 */
final class ProcessPlatformPaystackWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 45;

    public function __construct(public readonly int $webhookLogId)
    {
        $this->onQueue('billing');
        $this->afterCommit();
    }

    public function handle(OrganizationContext $context, SmsCreditPurchases $purchases): void
    {
        $log = DB::table('webhook_log')->where('id', $this->webhookLogId)->whereNull('organization_id')->first();

        if ($log === null || ! (bool) $log->signature_valid || $log->processed_at !== null) {
            return;
        }

        $reference = is_string($log->reference) ? $log->reference : null;
        $organizationId = $reference !== null ? DB::table('platform_payments')->where('paystack_reference', $reference)->value('organization_id') : null;
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if ($log->event !== 'charge.success' || ! $organization instanceof Organization) {
            $this->finish(PaymentOutcome::Ignored);

            return;
        }

        try {
            $outcome = $context->run($organization, fn (): PaymentOutcome => $purchases->settle($reference));
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
