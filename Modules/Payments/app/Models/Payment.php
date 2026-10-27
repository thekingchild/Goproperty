<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Payments\Contracts\PayerType;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;

/**
 * One incoming payment from a tenant (DATABASE_SCHEMA.md § 4 `payments`,
 * ARCHITECTURE.md § 6.4). Organization-scoped. Written only by
 * Payments\PaymentService (AGENT.md § 3 rule 5); nothing is mass
 * assignable.
 *
 * A confirmed payment is history (DATABASE_SCHEMA.md integrity rule 3):
 * it is never deleted (soft or hard) and its money, tenant, method and
 * allocations never change; only its receipt (P03-10b) may be attached,
 * and a later reversal may mark it refunded. The model refuses anything
 * else, whoever calls it. No payment is ever force-deleted (rule 7).
 *
 * The tenant and bill belong to other modules: the payment keeps their ids
 * and reads them through their contracts.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tenant_profile_id
 * @property int|null $bill_id
 * @property Money $amount_kobo
 * @property PaymentMethod $method
 * @property PaymentSource $source
 * @property PaymentStatus $status
 * @property CarbonImmutable $paid_at
 * @property string|null $payer_name
 * @property PayerType $payer_type
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $idempotency_key
 * @property string|null $paystack_reference
 * @property int|null $bank_statement_line_id
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by_user_id
 * @property string|null $confirmation_note
 * @property CarbonImmutable|null $rejected_at
 * @property int|null $rejected_by_user_id
 * @property string|null $rejection_reason
 * @property int|null $receipt_id
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Payment extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** What may still change on a confirmed payment. */
    private const array CHANGEABLE_ONCE_CONFIRMED = ['receipt_id', 'status', 'updated_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending_confirmation', 'payer_type' => 'individual'];

    protected static function booted(): void
    {
        self::updating(static function (Payment $payment): void {
            if ($payment->getRawOriginal('status') !== PaymentStatus::Confirmed->value) {
                return;
            }

            $changed = array_diff(array_keys($payment->getDirty()), self::CHANGEABLE_ONCE_CONFIRMED);

            if ($changed !== [] || ($payment->isDirty('status') && $payment->status !== PaymentStatus::Refunded)) {
                throw new LogicException('A confirmed payment is never changed; reverse it instead.');
            }
        });

        self::deleting(static function (Payment $payment): void {
            if ($payment->isForceDeleting()) {
                throw new LogicException('Payments are never deleted for good.');
            }

            if ($payment->getRawOriginal('status') === PaymentStatus::Confirmed->value) {
                throw new LogicException('A confirmed payment is never deleted; reverse it instead.');
            }
        });
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'source' => PaymentSource::class,
            'status' => PaymentStatus::class,
            'payer_type' => PayerType::class,
            'paid_at' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }
}
