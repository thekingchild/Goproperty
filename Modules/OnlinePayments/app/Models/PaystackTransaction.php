<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\OnlinePayments\Enums\ConnectionMode;
use Modules\OnlinePayments\Enums\TransactionStatus;

/**
 * An online payment GoProperty started in the organization's own Paystack
 * account for a bill (P03-08): the amount asked for, and once settled what
 * Paystack confirmed and the payment recorded for it. Organization-scoped;
 * written only by Transactions\PaystackTransactions. Settled once
 * (TransactionStatus::isFinal()); never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $bill_id
 * @property int $tenant_profile_id
 * @property string $reference
 * @property Money $amount_kobo
 * @property string $currency
 * @property ConnectionMode $mode
 * @property TransactionStatus $status
 * @property string|null $hold_reason
 * @property Money|null $paid_amount_kobo
 * @property string|null $paid_currency
 * @property string|null $channel
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $verified_at
 * @property int|null $payment_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaystackTransaction extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'initialized', 'currency' => 'NGN'];

    protected static function booted(): void
    {
        self::updating(static function (self $transaction): void {
            if ($transaction->isDirty(['bill_id', 'tenant_profile_id', 'reference', 'amount_kobo', 'currency', 'mode'])) {
                throw new LogicException('An online payment keeps the bill and amount it was started for.');
            }

            $was = TransactionStatus::from((string) $transaction->getRawOriginal('status'));

            if ($was->isFinal() && $transaction->isDirty()) {
                throw new LogicException('A settled online payment does not change.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Online payments are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'paid_amount_kobo' => MoneyCast::class,
            'mode' => ConnectionMode::class,
            'status' => TransactionStatus::class,
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
