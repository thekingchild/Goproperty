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
use Modules\SaasBilling\Enums\PlatformPaymentStatus;

/**
 * A payment of a platform invoice through GoProperty's own Paystack account
 * (DATABASE_SCHEMA.md § 3 `platform_payments`; BILLING_PAYMENTS.md § 4).
 * Organization-scoped; one row per Paystack reference (unique); written
 * only by SmsCredits\SmsCreditPurchases and Payments\SubscriptionCheckout
 * (started) and Payments\PlatformPaymentSettlement (settled); never
 * deleted, and a settled one never changes.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_invoice_id
 * @property string $method
 * @property Money $amount_kobo
 * @property string $currency
 * @property string|null $paystack_reference
 * @property string|null $paystack_channel
 * @property PlatformPaymentStatus $status
 * @property string|null $hold_reason
 * @property Money|null $paid_amount_kobo
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $initiated_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PlatformInvoice $invoice
 */
final class PlatformPayment extends Model
{
    use BelongsToOrganization;

    public const string PAYSTACK = 'paystack';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $payment): void {
            $settled = $payment->getOriginal('status');

            if (($settled instanceof PlatformPaymentStatus && $settled->isSettled()) || $payment->isDirty(['platform_invoice_id', 'amount_kobo', 'currency', 'paystack_reference', 'method'])) {
                throw new LogicException('A platform payment keeps what was asked, and a settled one never changes.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Platform payments are never deleted.'));
    }

    /** @return BelongsTo<PlatformInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlatformPaymentStatus::class,
            'amount_kobo' => MoneyCast::class,
            'paid_amount_kobo' => MoneyCast::class,
            'verified_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
