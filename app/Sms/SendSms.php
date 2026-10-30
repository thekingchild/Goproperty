<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Sms\Credits\SmsCredits;
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
 *
 * SMS credits (P05-03): one credit per page is taken in the same
 * transaction as the claim, under the organization's balance lock
 * (Credits\SmsCredits::take), so parallel sends never take the balance
 * below zero. With no credit left the delivery ends `no_credit` and GoText
 * is never called. A retry takes nothing again. When the last attempt
 * ends with GoText refusing the message outright, and no attempt's outcome
 * was unknown, the credit is given back.
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

    public function handle(OrganizationContext $context, SmsSender $sender, SmsCredits $credits): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $context->run($organization, function () use ($sender, $credits): void {
            $delivery = $this->claim($credits);

            if ($delivery === null) {
                return;
            }

            try {
                $receipt = $sender->send($delivery->recipient_contact, $delivery->body);
            } catch (SmsFailed $failed) {
                $delivery->forceFill([
                    'status' => DeliveryStatus::Queued,
                    'error' => $failed->getMessage(),
                    'outcome_unknown' => $delivery->outcome_unknown || $failed->outcomeUnknown,
                ])->save();

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
            $credits = app(SmsCredits::class);
            $credits->ensureBalance();

            DB::transaction(function () use ($exception, $credits): void {
                $delivery = NotificationDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

                if ($delivery !== null && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Sending], true)) {
                    $delivery->forceFill([
                        'status' => DeliveryStatus::Failed,
                        'failed_at' => now(),
                        'error' => $delivery->error ?? ($exception instanceof SmsFailed ? $exception->getMessage() : 'The message could not be sent.'),
                    ])->save();

                    // GoText refused it outright (it answered, and never without
                    // an answer before): it was not sent, so its credit comes back.
                    if ($exception instanceof SmsFailed && ! $exception->outcomeUnknown && ! $delivery->outcome_unknown) {
                        $credits->giveBack($delivery);
                    }
                }
            });
        });
    }

    /** The delivery, claimed for this attempt with its credit taken, or null when there is nothing to send. */
    private function claim(SmsCredits $credits): ?NotificationDelivery
    {
        // Outside the transaction: creating the balance row inside it could deadlock two senders.
        $credits->ensureBalance();

        return DB::transaction(function () use ($credits): ?NotificationDelivery {
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

            // One credit per page (every SMS is one GSM page, SmsText); taken
            // once, on the first attempt, under the balance lock.
            if (! $credits->take($delivery)) {
                $delivery->forceFill([
                    'status' => DeliveryStatus::NoCredit,
                    'error' => 'No SMS credit left: not sent. The organization can buy more in Settings → SMS credits.',
                ])->save();

                return null;
            }

            $delivery->forceFill(['status' => DeliveryStatus::Sending, 'attempts' => $delivery->attempts + 1])->save();

            return $delivery;
        }, 3);
    }
}
