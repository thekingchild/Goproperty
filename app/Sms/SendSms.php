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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one queued SMS (P05-02), on `notifications`. Failed attempts are
 * retried with backoff (1, 5, then 30 minutes) and the delivery is marked
 * `failed` once the last one fails; the console's delivery log shows it.
 *
 * At most once: the row is claimed (`sending`) in its own transaction
 * before GoText is called. A row found still `sending` means an earlier
 * attempt died mid-call, so whether GoText took it is unknown: it is
 * marked failed rather than sent again (a duplicate costs the agency a
 * credit and confuses the tenant).
 */
final class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 40;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $deliveryId,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(OrganizationContext $context, SmsSender $sender): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($sender): void {
            $delivery = $this->claim();

            if ($delivery === null) {
                return;
            }

            try {
                $receipt = $sender->send($delivery->recipient_contact, $delivery->body);
            } catch (SmsFailed $failed) {
                $delivery->forceFill(['status' => DeliveryStatus::Queued, 'error' => $failed->getMessage()])->save();

                throw $failed;
            }

            $delivery->forceFill([
                'status' => DeliveryStatus::Sent,
                'provider_message_id' => $receipt->messageId,
                'provider_status' => $receipt->providerStatus !== null ? mb_substr($receipt->providerStatus, 0, 40) : null,
                'pages' => $receipt->pages,
                'cost_kobo' => $receipt->costKobo,
                'error' => null,
                'sent_at' => now(),
            ])->save();

            SmsSent::dispatch($this->organizationId, $delivery->id, $delivery->source_type, $delivery->source_id);

            if ($receipt->messageId !== null) {
                CheckSmsStatus::dispatch($this->organizationId, $delivery->id, 1)->delay(now()->addSeconds(CheckSmsStatus::DELAYS[0]));
            }
        });
    }

    /** Called by the queue once the last try has failed. */
    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        app(OrganizationContext::class)->run($organization, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $delivery = NotificationDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

                if ($delivery !== null && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Sending], true)) {
                    $delivery->forceFill([
                        'status' => DeliveryStatus::Failed,
                        'failed_at' => now(),
                        'error' => $delivery->error ?? ($exception instanceof SmsFailed ? $exception->getMessage() : 'The message could not be sent.'),
                    ])->save();
                }
            });
        });
    }

    /** The delivery, claimed for this attempt, or null when there is nothing to send. */
    private function claim(): ?NotificationDelivery
    {
        return DB::transaction(function (): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null) {
                return null;
            }

            if ($delivery->status === DeliveryStatus::Sending) {
                $delivery->forceFill([
                    'status' => DeliveryStatus::Failed,
                    'failed_at' => now(),
                    'error' => 'An earlier attempt stopped while sending, so it is not known whether GoText took the message. It is not sent again.',
                ])->save();

                return null;
            }

            if ($delivery->status !== DeliveryStatus::Queued) {
                return null;
            }

            $delivery->forceFill(['status' => DeliveryStatus::Sending, 'attempts' => $delivery->attempts + 1])->save();

            return $delivery;
        }, 3);
    }
}
