<?php

declare(strict_types=1);

namespace App\Platform\Deliveries;

use App\Platform\Customers\Customer;
use App\Policies\DeliveryLogEntryPolicy;
use App\Sms\DeliveryStatus;
use App\Sms\SmsText;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The console's read model of `notification_deliveries`, across
 * organizations (P05-02; PLATFORM_CONSOLE.md § 5 "Delivery", the support
 * agent's "delivery logs"): every SMS and what became of it, so failed
 * sends surface for support (P05-05 builds the health dashboard on the
 * same rows). Read-only: deliveries change only through App\Sms. The
 * number is shown masked and the text is not shown at all.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $notification_type
 * @property string $channel
 * @property string $recipient_contact
 * @property DeliveryStatus $status
 * @property string $provider
 * @property string|null $provider_message_id
 * @property string|null $provider_status
 * @property string|null $error
 * @property int $attempts
 * @property int|null $pages
 * @property int|null $cost_kobo
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property-read Customer $organization
 */
#[UsePolicy(DeliveryLogEntryPolicy::class)]
final class DeliveryLogEntry extends Model
{
    protected $table = 'notification_deliveries';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Deliveries change only through App\Sms.'));
        self::deleting(fn (): never => throw new LogicException('Deliveries are never deleted.'));
    }

    public function maskedContact(): string
    {
        return SmsText::mask($this->recipient_contact);
    }

    /** @return BelongsTo<Customer, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
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
        ];
    }
}
