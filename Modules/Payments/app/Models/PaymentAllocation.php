<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Part of a confirmed payment settling one bill (DATABASE_SCHEMA.md § 4
 * `payment_allocations`). Organization-scoped; written by
 * Payments\PaymentService when the payment is confirmed, in the same
 * transaction as the bill's paid amount (Billing's Contracts\BillLedger),
 * and never changed or deleted afterwards (a reversal writes its own
 * records).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $payment_id
 * @property int $bill_id
 * @property Money $amount_kobo
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentAllocation extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Payment allocations are never changed.'));
        self::deleting(static fn (): never => throw new LogicException('Payment allocations are never deleted.'));
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_kobo' => MoneyCast::class];
    }
}
