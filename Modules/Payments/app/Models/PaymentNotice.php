<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One thing the tenant was told about a payment (P03-10b;
 * DATABASE_SCHEMA.md § 4 `payment_notices`): the payment, the transition
 * (`Notices\PaymentNoticeEvent`) and where it went. Unique per payment and
 * transition, written before the notification is sent, so a transition
 * handled twice tells the tenant once. Written only by
 * Modules\Payments\Notices\TenantNotices; never changed or deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property string $event
 * @property list<string> $channels
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentNotice extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('What the tenant was told is never changed.'));
        self::deleting(static fn (): never => throw new LogicException('What the tenant was told is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['channels' => 'array'];
    }
}
