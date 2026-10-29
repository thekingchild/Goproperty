<?php

declare(strict_types=1);

namespace Modules\Reminders\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Reminders\Enums\ReminderChannel;
use Modules\Reminders\Enums\ReminderStatus;
use Modules\Reminders\Enums\ReminderType;
use Modules\Reminders\Enums\SkipReason;

/**
 * One reminder rung for one recipient on one channel (DATABASE_SCHEMA.md
 * § 7 `reminders`; P05-01, ARCHITECTURE.md § 6.7). Organization-scoped.
 * Written only by Reminders\ReminderPlanner (the plan, unique by
 * `dedupe_key`) and Reminders\ReminderSender (sent or skipped, then
 * `delivered_at`); what was planned never changes, and rows are never
 * deleted. Nothing here is mass assignable.
 *
 * @property int $id
 * @property int $organization_id
 * @property ReminderType $type
 * @property string $remindable_type `bill` or `lease`
 * @property int $remindable_id
 * @property int|null $lease_id
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable $due_date
 * @property int $offset_days
 * @property CarbonImmutable $send_on
 * @property ReminderChannel $channel
 * @property string $recipient `user:{id}` or `tenant:{id}`
 * @property int|null $recipient_user_id
 * @property int|null $recipient_tenant_profile_id
 * @property string|null $recipient_email
 * @property CarbonImmutable|null $send_at
 * @property ReminderStatus $status
 * @property SkipReason|null $skip_reason
 * @property string $dedupe_key
 * @property string $notification_id
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Reminder extends Model
{
    use BelongsToOrganization;

    public const string BILL = 'bill';

    public const string LEASE = 'lease';

    /** What the sender may fill in after the plan is written. */
    private const array OUTCOME_COLUMNS = ['status', 'skip_reason', 'sent_at', 'delivered_at', 'updated_at'];

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (Reminder $reminder): void {
            if (array_diff(array_keys($reminder->getDirty()), self::OUTCOME_COLUMNS) !== []) {
                throw new LogicException('A planned reminder never changes; only its outcome is recorded.');
            }
        });
        self::deleting(static fn (): never => throw new LogicException('Reminders are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'channel' => ReminderChannel::class,
            'status' => ReminderStatus::class,
            'skip_reason' => SkipReason::class,
            'period_start' => 'immutable_date',
            'due_date' => 'immutable_date',
            'send_on' => 'immutable_date',
            'offset_days' => 'integer',
            'send_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
