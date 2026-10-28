<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * An opening balance an import brought over (DATABASE_SCHEMA.md § 4
 * `opening_balances`, DATA_IMPORT.md § 3; P03-13b): what a tenant owed
 * (positive: became the `opening_balance` bill `bill_id`) or had in credit
 * (negative: the tenant credit entry `credit_entry_id`) on the as-of date.
 * Organization-scoped; written only by Import\OpeningBalancesImportTarget;
 * soft deleted by the import's rollback, never deleted for good. Nothing is
 * mass assignable.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tenant_profile_id
 * @property int|null $property_id
 * @property int|null $unit_id
 * @property int|null $lease_id
 * @property CarbonImmutable $as_of_date
 * @property Money $amount_kobo
 * @property string|null $description
 * @property int|null $bill_id
 * @property int|null $credit_entry_id
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class OpeningBalance extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected static function booted(): void
    {
        self::deleting(static function (OpeningBalance $balance): void {
            if ($balance->isForceDeleting()) {
                throw new LogicException('Opening balances are never deleted for good.');
            }
        });
    }

    /** Whether the tenant had credit (the amount is negative) rather than owing. */
    public function isCredit(): bool
    {
        return $this->amount_kobo->isNegative();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'as_of_date' => 'immutable_date',
            'amount_kobo' => MoneyCast::class,
            'bill_id' => 'integer',
            'credit_entry_id' => 'integer',
            'import_batch_id' => 'integer',
        ];
    }
}
