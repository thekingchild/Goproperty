<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;

/**
 * One of GoProperty's invoices to a customer (DATABASE_SCHEMA.md § 3
 * `platform_invoices`): in P05-03 an SMS credit bundle. Organization-
 * scoped; written only by SmsCredits\SmsCreditPurchases; never deleted,
 * and a paid one never changes.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $number
 * @property InvoiceKind $kind
 * @property int|null $sms_credits
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

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $invoice): void {
            if ($invoice->getOriginal('status') === InvoiceStatus::Paid || $invoice->isDirty(['number', 'kind', 'sms_credits', 'subtotal_kobo', 'vat_kobo', 'total_kobo'])) {
                throw new LogicException('An issued platform invoice keeps its number and amounts, and a paid one never changes.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Platform invoices are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'sms_credits' => 'integer',
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
