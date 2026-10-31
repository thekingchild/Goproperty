<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\SaasBilling\Enums\DunningStep;

/**
 * A notice the dunning ladder sent an organization's owners (P06-05;
 * BILLING_PAYMENTS.md § 6, DATABASE_SCHEMA.md § 2 `dunning_notices`).
 * Organization-scoped; written only by Dunning\DunningLadder, before the
 * notice is queued, so each is sent once; never changed or deleted (only
 * the counts of who was told are filled in when it is written).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $subscription_id
 * @property DunningStep $step
 * @property CarbonImmutable $notice_date
 * @property CarbonImmutable|null $next_step_on
 * @property int $overdue_kobo
 * @property list<string> $invoice_numbers
 * @property int $recipients
 * @property int $sms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class DunningNotice extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (self $notice): void {
            if ($notice->isDirty(['subscription_id', 'step', 'notice_date', 'next_step_on', 'overdue_kobo', 'invoice_numbers'])) {
                throw new LogicException('A dunning notice keeps what it was sent about.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Dunning notices are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subscription_id' => 'integer',
            'step' => DunningStep::class,
            'notice_date' => 'immutable_date',
            'next_step_on' => 'immutable_date',
            'overdue_kobo' => 'integer',
            'invoice_numbers' => 'array',
            'recipients' => 'integer',
            'sms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
