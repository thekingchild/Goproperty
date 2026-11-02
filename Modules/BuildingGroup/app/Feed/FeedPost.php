<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Enums\Reaction;

/** A post as the feed shows it. `html` is cleaned again on the way out (Posts\RichText). */
final readonly class FeedPost
{
    /**
     * @param  list<FeedImage>  $images
     * @param  list<ReactionCount>  $reactions
     * @param  list<FeedComment>  $comments
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
    ) {}
}
