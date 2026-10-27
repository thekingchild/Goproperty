<?php

declare(strict_types=1);

namespace Modules\Collections\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Collections\Enums\DateOrder;

/**
 * The column mapping saved for one bank account (ONBOARDING_COMPLIANCE.md
 * § 4.1: "Column mapping is saved per bank"): field => the header text of
 * the bank's export, and how its dates are written. The next statement of
 * the account is mapped from it when its headers are all there.
 * Organization-scoped; written only by Statements\StatementImports.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $organization_bank_account_id
 * @property array<string, string> $columns field => header text
 * @property DateOrder $date_order
 * @property int|null $saved_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BankStatementColumnMapping extends Model
{
    use BelongsToOrganization;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'date_order' => DateOrder::class,
        ];
    }
}
