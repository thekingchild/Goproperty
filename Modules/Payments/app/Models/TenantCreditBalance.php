<?php

declare(strict_types=1);

namespace Modules\Payments\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A tenant's credit now (`tenant_credit_balances`): the sum of their
 * credit entries, kept on one row that Credit\TenantCredit locks before
 * adding or taking credit, so two transactions never spend the same credit.
 * Organization-scoped; never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tenant_profile_id
 * @property Money $balance_kobo
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TenantCreditBalance extends Model
{
    use BelongsToOrganization;

    /** @var array<string, mixed> */
    protected $attributes = ['balance_kobo' => 0];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Tenant credit balances are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['balance_kobo' => MoneyCast::class];
    }
}
