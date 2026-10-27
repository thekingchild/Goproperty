<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a bill (DATABASE_SCHEMA.md § 4 `bill_items`): a label, an
 * optional note and period, and the line's total in kobo (`quantity` is
 * shown, not multiplied). Organization-scoped; written with its bill by
 * Bills\BillIssuer and never changed or deleted afterwards (an issued
 * invoice is history).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $bill_id
 * @property string $label
 * @property string|null $note
 * @property Money $amount_kobo
 * @property int $quantity
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BillItem extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = ['quantity' => 1];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Bill lines are never changed once issued.'));
        self::deleting(static fn (): never => throw new LogicException('Bill lines are never deleted.'));
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'quantity' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'meta' => 'array',
        ];
    }
}
