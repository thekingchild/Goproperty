<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingCycle;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;

/**
 * One of GoProperty's invoices to a customer (DATABASE_SCHEMA.md § 2
 * `platform_invoices`): an SMS credit bundle (P05-03, written by
 * SmsCredits\SmsCreditPurchases) or a subscription's billing period
 * (P06-02, written by Metering\SubscriptionMetering, with the terms and
 * the count it was worked out from). Organization-scoped; never deleted;
 * an issued one keeps its number, amounts and terms, and a paid one never
 * changes, except that refunding the whole payment that paid a
 * subscription invoice opens it again (P06-07b, BILLING_PAYMENTS.md § 8:
 * reopenAfterRefund()).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $number
 * @property InvoiceKind $kind
 * @property int|null $sms_credits
 * @property int|null $subscription_id
 * @property int|null $plan_version_id
 * @property string|null $plan_name
 * @property BillingCycle|null $billing_cycle
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property int|null $units_billed
 * @property int|null $included_units
 * @property int|null $extra_units
 * @property Money|null $base_price_kobo
 * @property Money|null $unit_price_kobo
 * @property int|null $months_billed
 * @property int|null $days_billed
 * @property int|null $days_in_month
 * @property string|null $vat_percent
 * @property Money $subtotal_kobo
 * @property Money $vat_kobo
 * @property Money $total_kobo
 * @property InvoiceStatus $status
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformInvoice extends Model
{
    use BelongsToOrganization;

    /** What an issued invoice keeps for good. */
    private const array ISSUED = [
        'number', 'kind', 'sms_credits', 'subtotal_kobo', 'vat_kobo', 'total_kobo',
        'subscription_id', 'plan_version_id', 'plan_name', 'billing_cycle', 'period_start', 'period_end',
        'units_billed', 'included_units', 'extra_units', 'base_price_kobo', 'unit_price_kobo',
        'months_billed', 'days_billed', 'days_in_month', 'vat_percent',
    ];

    protected $guarded = ['*'];

    /** Set only while reopenAfterRefund() saves. */
    private static bool $reopening = false;

    protected static function booted(): void
    {
        self::updating(static function (self $invoice): void {
            if (self::$reopening && ! $invoice->isDirty(self::ISSUED) && array_diff(array_keys($invoice->getDirty()), ['status', 'paid_at', 'due_date', 'updated_at']) === []) {
                return;
            }

            if ($invoice->getOriginal('status') === InvoiceStatus::Paid || $invoice->isDirty(self::ISSUED)) {
                throw new LogicException('An issued platform invoice keeps its number and amounts, and a paid one never changes.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Platform invoices are never deleted.'));
    }

    /**
     * A paid subscription invoice is owed again: the whole payment that paid
     * it was refunded (Refunds\PlatformRefunds only, inside its transaction,
     * the invoice locked). Due $due, so the dunning ladder follows it.
     */
    public function reopenAfterRefund(CarbonImmutable $due): void
    {
        if ($this->status !== InvoiceStatus::Paid || $this->kind !== InvoiceKind::Subscription) {
            throw new LogicException('Only a paid subscription invoice is opened again by a refund.');
        }

        $this->status = InvoiceStatus::Open;
        $this->paid_at = null;
        $this->due_date = $due;
        self::$reopening = true;

        try {
            $this->save();
        } finally {
            self::$reopening = false;
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'sms_credits' => 'integer',
            'subscription_id' => 'integer',
            'plan_version_id' => 'integer',
            'billing_cycle' => BillingCycle::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'units_billed' => 'integer',
            'included_units' => 'integer',
            'extra_units' => 'integer',
            'base_price_kobo' => MoneyCast::class,
            'unit_price_kobo' => MoneyCast::class,
            'months_billed' => 'integer',
            'days_billed' => 'integer',
            'days_in_month' => 'integer',
            'subtotal_kobo' => MoneyCast::class,
            'vat_kobo' => MoneyCast::class,
            'total_kobo' => MoneyCast::class,
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
