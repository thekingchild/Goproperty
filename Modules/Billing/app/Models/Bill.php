<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Casts\MoneyCast;
use App\Media\HasOrganizationMedia;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\Billing\Enums\BillableType;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Spatie\MediaLibrary\HasMedia;

/**
 * A bill to a tenant (DATABASE_SCHEMA.md § 4 `bills`, ARCHITECTURE.md
 * § 6.4). Organization-scoped. Written only by the Billing module:
 * Bills\BillIssuer creates it, Bills\BillService voids it and flags it
 * overdue, Bills\Ledger (Contracts\BillLedger) applies payments to it.
 * Nothing here is mass assignable.
 *
 * The lease, tenant, unit and building belong to other modules: the bill
 * keeps their ids and reads them through their contracts, never their
 * models (ARCHITECTURE.md § 8 rule 3). `billable_type` is a short key
 * (BillableType), not a class name.
 *
 * What the invoice printed at issue never changes (P03-05c, issued
 * invoices are evidence): the number, the payment reference, the
 * verification code, the issue time, the branding snapshot and the
 * parties (`details`); `document_media_id` is filled once, when the PDF
 * is first rendered (Documents\Invoices, kept in this bill's
 * `issued_documents` media). Status and money change through the Billing
 * services only.
 *
 * @property int $id
 * @property int $organization_id
 * @property BillType $type
 * @property BillableType $billable_type
 * @property int $billable_id
 * @property int|null $lease_id
 * @property int $tenant_profile_id
 * @property int|null $property_id
 * @property int|null $unit_id
 * @property string $payment_reference
 * @property string $number
 * @property string $description
 * @property Money $amount_kobo
 * @property Money $paid_kobo
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property BillStatus $status
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property int|null $document_media_id
 * @property array<string, mixed> $branding_snapshot
 * @property array<string, mixed>|null $details
 * @property string $verification_code
 * @property int|null $created_by_user_id
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Bill extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia, SoftDeletes;

    /** Written at issue and never changed afterwards (the invoice as printed). */
    public const array ISSUED_COLUMNS = ['number', 'payment_reference', 'verification_code', 'issued_at', 'branding_snapshot', 'details'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'unpaid', 'paid_kobo' => 0];

    protected static function booted(): void
    {
        self::updating(static function (self $bill): void {
            if (array_intersect(array_keys($bill->getDirty()), self::ISSUED_COLUMNS) !== []) {
                throw new LogicException('An issued bill keeps the number, reference, code, branding and parties it was issued with.');
            }

            if ($bill->isDirty('document_media_id') && $bill->getRawOriginal('document_media_id') !== null) {
                throw new LogicException('An issued bill keeps the PDF it was first printed as.');
            }
        });
    }

    /** @return HasMany<BillItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class);
    }

    /** What is still owed: the amount less what has been paid (nothing on a void bill). */
    public function outstanding(): Money
    {
        if ($this->status === BillStatus::Void) {
            return Money::zero();
        }

        return Money::max(Money::zero(), $this->amount_kobo->minus($this->paid_kobo));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => BillType::class,
            'billable_type' => BillableType::class,
            'amount_kobo' => MoneyCast::class,
            'paid_kobo' => MoneyCast::class,
            'due_date' => 'immutable_date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => BillStatus::class,
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'branding_snapshot' => 'array',
            'details' => 'array',
            'document_media_id' => 'integer',
        ];
    }
}
