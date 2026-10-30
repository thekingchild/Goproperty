<?php

declare(strict_types=1);

namespace App\Sms;

use App\Enums\Concerns\HasValues;

/**
 * What became of a message (`notification_deliveries.status`, P05-02).
 * queued → sending → sent → delivered | undelivered; failed when every
 * retry failed or an attempt's outcome is unknown (never sent twice).
 */
enum DeliveryStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to send',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Undelivered => 'Not delivered',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::Sent, self::Sending, self::Queued => 'gray',
            self::Undelivered, self::Failed => 'danger',
        };
    }

    /** Nothing more will happen to it. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Undelivered, self::Failed], true);
    }
}
