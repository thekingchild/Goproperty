<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\BuildingGroup\Enums\MemberRole;
use Modules\BuildingGroup\Enums\MemberType;

/**
 * A member of a Building Group (DATABASE_SCHEMA.md § 6 `group_members`;
 * P07-01). A tenant's row (`tenant_profile_id`) is current while
 * `left_at` is empty: they hold an active lease in the building. Leaving
 * sets `left_at`; coming back clears it and sets a new `joined_at` on the
 * same row. Written only by Groups\BuildingGroups; never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $building_group_id
 * @property MemberType $member_type
 * @property int|null $tenant_profile_id
 * @property int|null $user_id
 * @property MemberRole $role
 * @property CarbonImmutable|null $muted_until
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GroupMember extends Model
{
    use BelongsToOrganization;

    protected $attributes = [
        'role' => 'member',
    ];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('A group member is never deleted; they leave.'));
    }

    /** @return BelongsTo<BuildingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BuildingGroup::class, 'building_group_id');
    }

    /**
     * Members now (not left).
     *
     * @param  Builder<GroupMember>  $query
     * @return Builder<GroupMember>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'member_type' => MemberType::class,
            'role' => MemberRole::class,
            'muted_until' => 'immutable_datetime',
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }
}
