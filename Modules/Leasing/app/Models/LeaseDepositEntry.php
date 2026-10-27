<?php

declare(strict_types=1);

namespace Modules\Leasing\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Leasing\Enums\DepositEntryType;

/**
 * One entry of a lease's deposit ledger (P03 decision 5,
 * DATABASE_SCHEMA.md § 3 `lease_deposit_entries`): a record of a deposit
 * taken, carried to a renewal, refunded or kept. GoProperty holds no money
 * (AGENT.md § 3 rule 8). Organization-scoped; written only by
 * Modules\Leasing\Leases\LeaseService, and never changed or deleted once
 * written. `amount_kobo` is signed: a refund is negative.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $lease_id
 * @property DepositEntryType $type
 * @property Money $amount_kobo
 * @property string|null $note
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LeaseDepositEntry extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        // A ledger: entries are added, never rewritten (history).
        self::updating(static fn (): never => throw new LogicException('Deposit entries are never changed: add another entry.'));
        self::deleting(static fn (): never => throw new LogicException('Deposit entries are never deleted.'));
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DepositEntryType::class,
            'amount_kobo' => MoneyCast::class,
        ];
    }
}
