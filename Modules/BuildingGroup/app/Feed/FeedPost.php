<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\Reaction;

/**
 * A post as the feed shows it. `html` is cleaned again on the way out
 * (Posts\RichText). Members only ever get published posts; moderators
 * also get hidden and removed ones, with their `trail` and `openReports`
 * (P07-04).
 */
final readonly class FeedPost
{
    /**
     * @param  list<FeedImage>  $images
     * @param  list<ReactionCount>  $reactions
     * @param  list<FeedComment>  $comments
     * @param  list<TrailEntry>  $trail
     */
    public function __construct(
        public int $id,
        public string $author,
        public ?string $badge,
        public bool $mine,
        public ?string $html,
        public ?CarbonImmutable $at,
        public int $imageCount,
        public array $images,
        public array $reactions,
        public ?Reaction $myReaction,
        public array $comments,
        public int $commentCount,
        public bool $canDelete,
        public PostStatus $status = PostStatus::Published,
        public bool $pinned = false,
        public bool $fromTenant = false,
        public array $trail = [],
        public int $openReports = 0,
        public bool $reportedByMe = false,
    ) {}
}
