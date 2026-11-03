<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\ReportReason;
use Modules\BuildingGroup\Enums\ReportStatus;

/**
 * A member's report of a post or comment (DATABASE_SCHEMA.md § 6
 * `group_reports`; P07-04): one per person and item. Written only by
 * Moderation\AbuseReports (new reports, and their resolution when a
 * moderator acts); never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $building_group_id
 * @property ReactableType $reportable_type
 * @property int $reportable_id
 * @property int|null $reporter_user_id
 * @property AuthorKind $reporter_kind
 * @property int|null $reporter_tenant_profile_id
 * @property ReportReason $reason
 * @property string|null $note
 * @property ReportStatus $status
 * @property int|null $resolved_by_user_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GroupReport extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'status' => 'open',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A report is never deleted.'));
    }

    /**
     * Reports no moderator has acted on yet.
     *
     * @param  Builder<GroupReport>  $query
     * @return Builder<GroupReport>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ReportStatus::Open);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reportable_type' => ReactableType::class,
            'reporter_kind' => AuthorKind::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
