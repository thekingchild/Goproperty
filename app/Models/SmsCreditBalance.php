<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An organization's SMS credits (P05-03; DATABASE_SCHEMA.md § 7
 * `sms_credit_balances`): what is left of this month's allowance and the
 * purchased credits, always the sum of its SmsCreditEntry rows. The row
 * every credit change locks (App\Sms\Credits\SmsCredits, the only writer).
 * Organization-scoped; never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $allowance_credits
 * @property int $purchased_credits
 * @property string|null $allowance_month
 * @property int $allowance_granted
 * @property string|null $low_notified_month
 * @property string|null $empty_notified_month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SmsCreditBalance extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = ['allowance_credits' => 0, 'purchased_credits' => 0, 'allowance_granted' => 0];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('SMS credit balances are never deleted.'));
    }

    public function total(): int
    {
        return $this->allowance_credits + $this->purchased_credits;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allowance_credits' => 'integer',
            'purchased_credits' => 'integer',
            'allowance_granted' => 'integer',
        ];
    }
}
