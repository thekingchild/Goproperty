<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use Illuminate\Support\Collection;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Models\GroupReaction;

/**
 * Reactions of the page's posts and comments, counted by emoji.
 *
 * @internal
 */
final readonly class ReactionTally
{
    /** @param  Collection<int, GroupReaction>  $rows */
    public function __construct(
        private Collection $rows,
        private int $viewerId,
    ) {}

    /** @return list<ReactionCount> in the curated set's order, only emojis someone chose */
    public function counts(ReactableType $type, int $id): array
    {
        $rows = $this->rows->filter(static fn (GroupReaction $row): bool => $row->reactable_type === $type && $row->reactable_id === $id);
        $counts = [];

        foreach (Reaction::cases() as $reaction) {
            $chosen = $rows->filter(static fn (GroupReaction $row): bool => $row->emoji === $reaction);

            if ($chosen->isNotEmpty()) {
                $counts[] = new ReactionCount($reaction, $chosen->count(), $chosen->contains(fn (GroupReaction $row): bool => $row->user_id === $this->viewerId));
            }
        }

        return $counts;
    }

    public function mine(ReactableType $type, int $id): ?Reaction
    {
        return $this->rows->first(fn (GroupReaction $row): bool => $row->reactable_type === $type && $row->reactable_id === $id && $row->user_id === $this->viewerId)?->emoji;
    }
}
