<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Payments\Enums\CreditReason;

/**
 * One movement of a tenant's credit (DATABASE_SCHEMA.md § 4
 * `tenant_credit_entries`): + an overpayment, − credit used for a bill.
 * Organization-scoped; written only by Credit\TenantCredit, never changed
 * or deleted (history: it stays with the organization that wrote it).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tenant_profile_id
 * @property Money $amount_kobo
 * @property CreditReason $reason
 * @property int|null $payment_id
 * @property int|null $bill_id
 * @property int|null $applied_bill_id
 * @property string|null $note
 * @property int|null $created_by_user_id
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TenantCreditEntry extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Tenant credit entries are never changed.'));
        self::deleting(static fn (): never => throw new LogicException('Tenant credit entries are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_kobo' => MoneyCast::class,
            'reason' => CreditReason::class,
        ];
    }
}
