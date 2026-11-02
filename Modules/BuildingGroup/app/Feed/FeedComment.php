<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Carbon\CarbonImmutable;
use Modules\BuildingGroup\Enums\Reaction;

/** A comment as the feed shows it, with its replies (one level). */
final readonly class FeedComment
{
    /**
     * @param  list<ReactionCount>  $reactions
     * @param  list<FeedComment>  $replies
     */
    public function __construct(
        public int $id,
        public string $author,
        public ?string $badge,
        public bool $mine,
        public string $html,
        public ?CarbonImmutable $at,
        public array $reactions,
        public ?Reaction $myReaction,
        public array $replies,
        public bool $canDelete,
    ) {}
}
