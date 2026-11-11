<?php

declare(strict_types=1);

namespace Modules\LateFees\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A late fee charged on one rent bill (PL-02; DATABASE_SCHEMA.md § 4
 * `late_fees`): the fee bill, the rate, what was unpaid (the base) and the
 * fee. One per rent bill, ever (unique). Organization-scoped; written only
 * by Charges\LateFeeCharger.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $rent_bill_id
 * @property int $fee_bill_id
 * @property int $rate_bps
 * @property Money $base_kobo
 * @property Money $amount_kobo
 * @property CarbonImmutable $charged_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class LateFee extends Model
{
    use BelongsToOrganization;

    protected $table = 'late_fees';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rent_bill_id' => 'integer',
            'fee_bill_id' => 'integer',
            'rate_bps' => 'integer',
            'base_kobo' => MoneyCast::class,
            'amount_kobo' => MoneyCast::class,
            'charged_on' => 'date',
        ];
    }
}
