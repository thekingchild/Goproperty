<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Webhooks;

use App\Models\NotificationDelivery;
use App\Sms\DeliveryStatus;
use App\WhatsApp\WhatsAppFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies Meta's message statuses to the matching `notification_deliveries`
 * row (channel `whatsapp`, Meta's message id), in the current organization.
 *
 * Never backwards, and safe to replay (Meta retries and may send statuses
 * out of order): sent → delivered → read only move forward (`read` is kept
 * as `delivered` with provider status `read`); `failed` counts only before
 * the message was delivered and turns it `undelivered`, with Meta's error,
 * and WhatsAppFailed goes out once (reminders then use their next channel).
 * An unknown message id changes nothing.
 */
final readonly class StatusUpdates
{
    private const array RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    /** @param  array{id: string, status: string, timestamp: int|null, error: string|null}  $status */
    public function apply(array $status): void
    {
        $failed = DB::transaction(static function () use ($status): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()
                ->where('channel', 'whatsapp')
                ->where('provider_message_id', $status['id'])
                ->lockForUpdate()
                ->first();

            if ($delivery === null || in_array($delivery->status, [DeliveryStatus::Failed, DeliveryStatus::Undelivered], true)) {
                return null;
            }

            $at = $status['timestamp'] !== null ? CarbonImmutable::createFromTimestamp($status['timestamp']) : CarbonImmutable::now();
            $now = self::rank($delivery);

            if ($status['status'] === 'failed') {
                if ($now >= self::RANK['delivered']) {
                    return null;
                }

                $delivery->forceFill([
                    'status' => DeliveryStatus::Undelivered,
                    'provider_status' => 'failed',
                    'failed_at' => $at,
                    'error' => $status['error'] ?? 'Meta could not deliver the message.',
                    'status_checked_at' => now(),
                ])->save();

                return $delivery;
            }

            $rank = self::RANK[$status['status']] ?? null;

            if ($rank === null || $rank <= $now) {
                return null;
            }

            $delivery->forceFill([
                'status' => $rank >= self::RANK['delivered'] ? DeliveryStatus::Delivered : DeliveryStatus::Sent,
                'provider_status' => $status['status'],
                'sent_at' => $delivery->sent_at ?? $at,
                'delivered_at' => $rank >= self::RANK['delivered'] ? ($delivery->delivered_at ?? $at) : $delivery->delivered_at,
                'status_checked_at' => now(),
            ])->save();

            return null;
        }, 3);

        if ($failed instanceof NotificationDelivery) {
            WhatsAppFailed::dispatch($failed->organization_id, $failed->id, $failed->source_type, $failed->source_id, (string) $failed->error);
        }
    }

    /** Where the delivery stands: 0 not sent yet, 1 sent, 2 delivered, 3 read. */
    private static function rank(NotificationDelivery $delivery): int
    {
        return match ($delivery->status) {
            DeliveryStatus::Delivered => $delivery->provider_status === 'read' ? self::RANK['read'] : self::RANK['delivered'],
            DeliveryStatus::Sent => self::RANK['sent'],
            default => 0,
        };
    }
}
