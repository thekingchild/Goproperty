<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\SaasBilling\Enums\RefundStatus;

/**
 * A refund of a payment into GoProperty's own account (P06-07b;
 * BILLING_PAYMENTS.md § 8; DATABASE_SCHEMA.md § 2 `platform_refunds`):
 * through Paystack's refund API for a Paystack payment, or GoProperty's own
 * bank transfer (recorded with its reference) for a bank-transfer payment.
 * Organization-scoped; written only by Refunds\PlatformRefunds; never
 * deleted, and what was asked never changes.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_payment_id
 * @property int $platform_invoice_id
 * @property string $method
 * @property Money $amount_kobo
 * @property RefundStatus $status
 * @property string $reason
 * @property int $requested_by_staff_id
 * @property int|null $approval_request_id
 * @property int|null $executed_by_staff_id
 * @property CarbonImmutable|null $executed_at
 * @property string|null $paystack_refund_id
 * @property string|null $bank_reference
 * @property bool $invoice_reopened
 * @property CarbonImmutable|null $processed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PlatformPayment $payment
 */
final class PlatformRefund extends Model
{
    use BelongsToOrganization;

    /** What a refund keeps from the moment it is asked for. */
    private const array ASKED = ['organization_id', 'platform_payment_id', 'platform_invoice_id', 'method', 'amount_kobo', 'reason', 'requested_by_staff_id'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $refund): void {
            $was = $refund->getOriginal('status');

            if ($refund->isDirty(self::ASKED) || $was === RefundStatus::NotApproved || $was === RefundStatus::Processed) {
                throw new LogicException('A refund keeps what was asked, and a finished one never changes.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Refunds are never deleted.'));
    }

    /** @return BelongsTo<PlatformPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformPayment::class, 'platform_payment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_kobo' => MoneyCast::class,
            'invoice_reopened' => 'boolean',
            'executed_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
