<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Customers\Customer;
use App\Policies\PlatformPaymentRecordPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The console's read model of payments into GoProperty's own account
 * (P06-07b; `platform_payments`, across organizations): Paystack checkouts
 * and bank transfers, with the invoice's number and what is refunded.
 * Read-only: payments change only in SaasBilling (settlement, transfer
 * reviews); refunds go through App\Platform\Billing\Refunds.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $platform_invoice_id
 * @property string $method paystack, bank_transfer
 * @property int $amount_kobo
 * @property int|null $paid_amount_kobo
 * @property string|null $paystack_reference
 * @property string|null $paystack_channel
 * @property string $status pending, confirmed, failed, held, rejected
 * @property string|null $hold_reason
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $created_at
 * @property string|null $invoice_number
 * @property string|null $invoice_kind
 * @property int|null $refunded_kobo
 * @property int|null $awaiting_kobo
 * @property-read Customer $customer
 * @property-read PlatformInvoiceRecord $invoice
 */
#[UsePolicy(PlatformPaymentRecordPolicy::class)]
final class PlatformPaymentRecord extends Model
{
    public const array STATUSES = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'failed' => 'Failed', 'held' => 'Held for staff', 'rejected' => 'Not accepted'];

    public const array METHODS = ['paystack' => 'Paystack', 'bank_transfer' => 'Bank transfer'];

    protected $table = 'platform_payments';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Platform payments are written only by SaasBilling.'));
        self::deleting(fn (): never => throw new LogicException('Platform payments are never deleted.'));
    }

    /**
     * With the invoice's number and kind, and what is refunded (processing
     * or processed) and waiting for approval.
     *
     * @template TQuery of Builder<covariant Model>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function withDetails(Builder $query): Builder
    {
        return $query->select('platform_payments.*')->addSelect(self::detailColumns());
    }

    /** @return array<string, \Illuminate\Database\Query\Builder> the columns withDetails() adds */
    public static function detailColumns(): array
    {
        return [
            'invoice_number' => DB::table('platform_invoices')->select('number')->whereColumn('platform_invoices.id', 'platform_payments.platform_invoice_id'),
            'invoice_kind' => DB::table('platform_invoices')->select('kind')->whereColumn('platform_invoices.id', 'platform_payments.platform_invoice_id'),
            'refunded_kobo' => DB::table('platform_refunds')->selectRaw('coalesce(sum(amount_kobo), 0)')
                ->whereColumn('platform_refunds.platform_payment_id', 'platform_payments.id')->whereIn('platform_refunds.status', ['processing', 'processed']),
            'awaiting_kobo' => DB::table('platform_refunds')->selectRaw('coalesce(sum(amount_kobo), 0)')
                ->whereColumn('platform_refunds.platform_payment_id', 'platform_payments.id')->where('platform_refunds.status', 'requested'),
        ];
    }

    /** What the payment took (Paystack's verified amount, else what was asked). */
    public function paidKobo(): int
    {
        return $this->paid_amount_kobo ?? $this->amount_kobo;
    }

    public function reference(): string
    {
        return $this->paystack_reference ?? 'Bank transfer';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return BelongsTo<PlatformInvoiceRecord, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoiceRecord::class, 'platform_invoice_id');
    }

    /** @return HasMany<PlatformRefundRecord, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PlatformRefundRecord::class, 'platform_payment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'paid_amount_kobo' => 'integer',
            'refunded_kobo' => 'integer',
            'awaiting_kobo' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
