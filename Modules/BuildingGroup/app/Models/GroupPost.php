<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Models;

use App\Media\HasOrganizationMedia;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\PostStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * A post in a Building Group (DATABASE_SCHEMA.md § 6 `group_posts`;
 * ARCHITECTURE.md § 6.8; P07-02): sanitized rich text (Posts\RichText)
 * and up to four images (media collection `group_images`, attachments).
 * Written only by Posts\GroupPosts, but for `status` and `pinned_at`,
 * which moderators change through Moderation\GroupModeration (P07-04:
 * hidden and removed posts vanish for members and stay for moderators).
 * Its author removes it with a soft delete; it is never deleted for good
 * (the model throws).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $building_group_id
 * @property AuthorKind $author_kind
 * @property int|null $author_user_id
 * @property int|null $author_tenant_profile_id
 * @property string|null $body
 * @property int $image_count
 * @property int $comment_count
 * @property PostStatus $status
 * @property CarbonImmutable|null $pinned_at
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class GroupPost extends Model implements HasMedia
{
    use BelongsToOrganization;
    use HasOrganizationMedia;
    use SoftDeletes;

    /** The post's images (config `media.collections.group_images`, added by the module's provider). */
    public const string IMAGES = 'group_images';

    protected $attributes = [
        'status' => 'published',
        'image_count' => 0,
        'comment_count' => 0,
    ];

    protected static function booted(): void
    {
        self::forceDeleting(static fn (): never => throw new LogicException('A Building Group post is never deleted for good.'));
    }

    /** @return BelongsTo<BuildingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BuildingGroup::class, 'building_group_id');
    }

    /** @return HasMany<GroupComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(GroupComment::class);
    }

    /**
     * Posts everyone in the group sees.
     *
     * @param  Builder<GroupPost>  $query
     * @return Builder<GroupPost>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published);
    }

    /**
     * Posts a moderator sees: every status, never the authors' own removals
     * (soft deleted).
     *
     * @param  Builder<GroupPost>  $query
     * @return Builder<GroupPost>
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
            'pinned_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
