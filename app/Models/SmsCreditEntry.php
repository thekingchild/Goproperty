<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\SmsCreditPolicy;
use App\Sms\Credits\CreditPool;
use App\Sms\Credits\CreditReason;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of the SMS credit ledger (P05-03; DATABASE_SCHEMA.md § 7
 * `sms_credit_entries`): append-only, written only by
 * App\Sms\Credits\SmsCredits under the balance row's lock, never changed
 * or deleted. Organization-scoped.
 *
 * @property int $id
 * @property int $organization_id
 * @property CreditReason $reason
 * @property CreditPool $pool
 * @property int $credits
 * @property int $balance_after
 * @property string|null $month
 * @property int|null $notification_delivery_id
 * @property int|null $platform_invoice_id
 * @property int|null $platform_staff_id
 * @property string|null $note
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NotificationDelivery|null $delivery
 */
#[UsePolicy(SmsCreditPolicy::class)]
class SmsCreditEntry extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('SMS credit entries are never changed: write a new one.'));
        self::deleting(static fn (): never => throw new LogicException('SMS credit entries are never deleted.'));
    }

    /** @return BelongsTo<NotificationDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(NotificationDelivery::class, 'notification_delivery_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => CreditReason::class,
            'pool' => CreditPool::class,
            'credits' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
