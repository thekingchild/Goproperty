<?php

declare(strict_types=1);

namespace App\Models;

use App\Sms\DeliveryStatus;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One message sent, and what became of it (P05-02; DATABASE_SCHEMA.md § 7
 * `notification_deliveries`). Organization-scoped. Written only by
 * App\Sms\SmsOutbox and its jobs; the number, text and source never change
 * once written, and rows are never deleted (P05-03 charges credits from
 * them; P05-05 reports on them).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $notification_type
 * @property string $channel
 * @property int|null $recipient_user_id
 * @property string $recipient_contact
 * @property string|null $subject
 * @property string $body
 * @property DeliveryStatus $status
 * @property string $provider
 * @property string|null $provider_message_id
 * @property string|null $provider_status
 * @property string|null $error
 * @property int $attempts
 * @property int|null $pages
 * @property int|null $cost_kobo
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $dedupe_key
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $status_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationDelivery extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $delivery): void {
            if ($delivery->isDirty(['recipient_contact', 'body', 'channel', 'dedupe_key', 'source_type', 'source_id', 'notification_type'])) {
                throw new LogicException('A delivery keeps the message as it was queued.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Deliveries are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }
}
