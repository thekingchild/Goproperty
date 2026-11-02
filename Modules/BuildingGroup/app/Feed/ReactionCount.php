<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Modules\BuildingGroup\Enums\Reaction;

/** How many people chose one emoji on a post or comment, and whether the viewer is one of them. */
final readonly class ReactionCount
{
    public function __construct(
        public Reaction $reaction,
        public int $count,
        public bool $mine,
    ) {}
}
