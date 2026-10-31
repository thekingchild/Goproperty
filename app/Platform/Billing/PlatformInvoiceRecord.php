<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Customers\Customer;
use App\Policies\PlatformInvoiceRecordPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The console's read model of GoProperty's invoices to its customers
 * (P06-07b; `platform_invoices`, across organizations): subscription
 * invoices and SMS credit bundles. Read-only: invoices are written only by
 * SaasBilling (metering, settlement, refunds), never here.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $number
 * @property string $kind subscription, sms_credits
 * @property int|null $sms_credits
 * @property string|null $plan_name
 * @property string|null $billing_cycle
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int|null $units_billed
 * @property int $subtotal_kobo
 * @property int $vat_kobo
 * @property int $total_kobo
 * @property string $status open, paid, void
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property-read Customer $customer
 */
#[UsePolicy(PlatformInvoiceRecordPolicy::class)]
final class PlatformInvoiceRecord extends Model
{
    public const array STATUSES = ['open' => 'Open', 'paid' => 'Paid', 'void' => 'Void'];

    public const array KINDS = ['subscription' => 'Subscription', 'sms_credits' => 'SMS credits'];

    protected $table = 'platform_invoices';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Platform invoices are written only by SaasBilling.'));
        self::deleting(fn (): never => throw new LogicException('Platform invoices are never deleted.'));
    }

    /**
     * Open and due before today (Lagos).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function overdue(Builder $query): Builder
    {
        return $query->where('platform_invoices.status', 'open')->whereDate('platform_invoices.due_date', '<', CarbonImmutable::now('Africa/Lagos')->toDateString());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->due_date !== null && $this->due_date->toDateString() < CarbonImmutable::now('Africa/Lagos')->toDateString();
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Overdue' : (self::STATUSES[$this->status] ?? $this->status);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return HasMany<PlatformPaymentRecord, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPaymentRecord::class, 'platform_invoice_id');
    }

    /** @return HasMany<PlatformRefundRecord, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PlatformRefundRecord::class, 'platform_invoice_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sms_credits' => 'integer',
            'units_billed' => 'integer',
            'subtotal_kobo' => 'integer',
            'vat_kobo' => 'integer',
            'total_kobo' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
