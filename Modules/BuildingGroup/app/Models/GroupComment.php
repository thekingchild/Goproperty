<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\PostStatus;

/**
 * A comment on a Building Group post, or a reply to one (one level:
 * `parent_id` is a top-level comment of the same post; DATABASE_SCHEMA.md
 * § 6 `group_comments`; P07-02). Same sanitized rich text as posts, no
 * images. Written only by Posts\GroupPosts, but for `status`, which
 * moderators change through Moderation\GroupModeration (P07-04); its
 * author removes it with a soft delete; never deleted for good.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $group_post_id
 * @property int|null $parent_id
 * @property AuthorKind $author_kind
 * @property int|null $author_user_id
 * @property int|null $author_tenant_profile_id
 * @property string $body
 * @property PostStatus $status
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class GroupComment extends Model
{
    use BelongsToOrganization;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'published',
    ];

    protected static function booted(): void
    {
        self::forceDeleting(static fn (): never => throw new LogicException('A Building Group comment is never deleted for good.'));
    }

    /** @return BelongsTo<GroupPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(GroupPost::class, 'group_post_id');
    }

    /**
     * Comments everyone in the group sees.
     *
     * @param  Builder<GroupComment>  $query
     * @return Builder<GroupComment>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published);
    }

    /**
     * Comments a moderator sees: every status, never the authors' own
     * removals (soft deleted).
     *
     * @param  Builder<GroupComment>  $query
     * @return Builder<GroupComment>
     */
    public function scopeModeratable(Builder $query): Builder
    {
        return $query->whereIn('status', [PostStatus::Published, PostStatus::Hidden, PostStatus::Removed]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'author_kind' => AuthorKind::class,
            'status' => PostStatus::class,
            'edited_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
