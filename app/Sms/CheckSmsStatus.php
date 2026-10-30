<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Asks GoText what became of a sent SMS (P05-02; docs/plans/P05-subtasks.md
 * default 15: GoText has no delivery-report webhook that we know of, so we
 * poll `GET /sms/{uid}`): 5 minutes, 30 minutes, 2 hours and 12 hours
 * after it went, until it is delivered or not delivered. Still unknown
 * after the last check, it stays `sent`. A failed check waits for the next.
 */
final class CheckSmsStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds before each check. */
    public const array DELAYS = [300, 1800, 7200, 43200];

    public int $tries = 1;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $deliveryId,
        public readonly int $check,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(OrganizationContext $context, SmsSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($sender): void {
            $delivery = NotificationDelivery::query()->find($this->deliveryId);

            if ($delivery === null || $delivery->status !== DeliveryStatus::Sent || $delivery->provider_message_id === null) {
                return;
            }

            try {
                $report = $sender->status($delivery->provider_message_id);
            } catch (SmsFailed) {
                $report = new SmsReport(null, $delivery->provider_status);
            }

            $delivery->forceFill([
                'status' => $report->status ?? DeliveryStatus::Sent,
                'provider_status' => $report->providerStatus !== null ? mb_substr($report->providerStatus, 0, 40) : $delivery->provider_status,
                'status_checked_at' => now(),
                'delivered_at' => $report->status === DeliveryStatus::Delivered ? now() : null,
                'failed_at' => $report->status === DeliveryStatus::Undelivered ? now() : null,
            ])->save();

            if ($report->status === null && $this->check < count(self::DELAYS)) {
                self::dispatch($this->organizationId, $delivery->id, $this->check + 1)->delay(now()->addSeconds(self::DELAYS[$this->check]));
            }
        });
    }
}
