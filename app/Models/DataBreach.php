<?php

declare(strict_types=1);

namespace App\Models;

use App\Privacy\Breaches\BreachRisk;
use App\Privacy\Breaches\BreachStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry of the personal data breach register (P09-02; NDPA 2023 s. 40,
 * docs/runbooks/breach-response.md): from when GoProperty became aware of
 * it to its close. The Nigeria Data Protection Commission must be told
 * within 72 hours of `aware_at`. Platform-level; written only by
 * App\Privacy\Breaches\BreachRegister; never deleted.
 *
 * @property int $id
 * @property string|null $reference
 * @property string $title
 * @property string $description
 * @property Carbon|null $aware_at
 * @property Carbon|null $occurred_at
 * @property string|null $data_involved
 * @property int|null $people_affected
 * @property string|null $organizations_affected
 * @property string|null $likely_consequences
 * @property string|null $measures_taken
 * @property BreachRisk $risk
 * @property BreachStatus $status
 * @property Carbon|null $contained_at
 * @property Carbon|null $commission_notified_at
 * @property string|null $commission_reference
 * @property Carbon|null $customers_notified_at
 * @property Carbon|null $people_notified_at
 * @property string|null $not_notified_reason
 * @property Carbon|null $closed_at
 * @property string|null $close_note
 * @property int $recorded_by_staff_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlatformStaff|null $recordedBy
 */
class DataBreach extends Model
{
    public const int NOTICE_HOURS = 72;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Breach register entries are never deleted.'));
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'recorded_by_staff_id');
    }

    /** When the Commission must have been told: 72 hours after GoProperty became aware. */
    public function noticeDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->aware_at ?? $this->created_at ?? now())->addHours(self::NOTICE_HOURS);
    }

    /** Whether the Commission still has to be told (not told, and no recorded reason not to). */
    public function awaitsCommissionNotice(): bool
    {
        return $this->commission_notified_at === null && $this->not_notified_reason === null && $this->status !== BreachStatus::Closed;
    }

    public function isOverdue(): bool
    {
        return $this->awaitsCommissionNotice() && $this->noticeDeadline()->isPast();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aware_at' => 'datetime',
            'occurred_at' => 'datetime',
            'risk' => BreachRisk::class,
            'status' => BreachStatus::class,
            'contained_at' => 'datetime',
            'commission_notified_at' => 'datetime',
            'customers_notified_at' => 'datetime',
            'people_notified_at' => 'datetime',
            'closed_at' => 'datetime',
            'people_affected' => 'integer',
        ];
    }
}
