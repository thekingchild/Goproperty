<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Policies\PlatformRefundRecordPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The console's read model of refunds of payments into GoProperty's
 * account (P06-07b; `platform_refunds`, across organizations). Read-only:
 * refunds are written only by SaasBilling's Refunds\PlatformRefunds,
 * reached through App\Platform\Billing\Refunds.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_payment_id
 * @property int $platform_invoice_id
 * @property string $method paystack, bank_transfer
 * @property int $amount_kobo
 * @property string $status requested, not_approved, processing, processed, failed
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
 * @property-read Customer $customer
 * @property-read PlatformPaymentRecord $payment
 * @property-read PlatformStaff $requester
 * @property-read PlatformStaff|null $executor
 * @property-read ApprovalRequest|null $approval
 */
#[UsePolicy(PlatformRefundRecordPolicy::class)]
final class PlatformRefundRecord extends Model
{
    public const array STATUSES = [
        'requested' => 'Waiting for approval',
        'not_approved' => 'Not approved',
        'processing' => 'Processing at Paystack',
        'processed' => 'Refunded',
        'failed' => 'Failed',
    ];

    protected $table = 'platform_refunds';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Refunds are written only by SaasBilling\'s PlatformRefunds.'));
        self::deleting(fn (): never => throw new LogicException('Refunds are never deleted.'));
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'requested', 'processing' => 'warning',
            'processed' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return BelongsTo<PlatformPaymentRecord, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformPaymentRecord::class, 'platform_payment_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'requested_by_staff_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'executed_by_staff_id');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'invoice_reopened' => 'boolean',
            'executed_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
