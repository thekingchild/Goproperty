<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\BuildingGroup\Enums\ModerationAction;
use Modules\BuildingGroup\Enums\ModerationTarget;

/**
 * One thing a moderator did in a Building Group (DATABASE_SCHEMA.md § 6
 * `group_moderation_events`; P07-04): the trail moderators see on a hidden
 * or removed item. Written only by Moderation\GroupModeration, in the same
 * transaction as the change and its audit entry; append-only (the model
 * throws on any change or delete).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $building_group_id
 * @property int|null $actor_user_id
 * @property ModerationAction $action
 * @property ModerationTarget $target_type
 * @property int $target_id
 * @property string|null $reason
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class GroupModerationEvent extends Model
{
    use BelongsToOrganization;

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A moderation event is never changed.'));
        self::deleting(static fn (): never => throw new LogicException('A moderation event is never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ModerationAction::class,
            'target_type' => ModerationTarget::class,
            'details' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
