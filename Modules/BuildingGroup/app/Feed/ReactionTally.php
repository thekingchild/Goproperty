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
 * The rows are counted once, by item, when the tally is made: asking each
 * post and comment to filter every row of the page (a page is 20 posts
 * and their comments) took half a second of the group page under the
 * load test (P09-03, docs/runbooks/load-test.md).
 *
 * @internal
 */
final readonly class ReactionTally
{
    /** @var array<string, array<string, array{int, bool}>> item key => emoji => [count, chosen by the viewer] */
    private array $counts;

    /** @var array<string, Reaction> item key => the viewer's reaction (one a person: group_reactions_user_item_unique) */
    private array $mine;

    /** @param  Collection<int, GroupReaction>  $rows */
    public function __construct(Collection $rows, int $viewerId)
    {
        $counts = [];
        $mine = [];

        foreach ($rows as $row) {
            $key = self::key($row->reactable_type, $row->reactable_id);
            $emoji = $row->emoji;
            $byViewer = $row->user_id === $viewerId;
            [$count, $chosen] = $counts[$key][$emoji->value] ?? [0, false];
            $counts[$key][$emoji->value] = [$count + 1, $chosen || $byViewer];

            if ($byViewer) {
                $mine[$key] ??= $emoji;
            }
        }

        $this->counts = $counts;
        $this->mine = $mine;
    }

    /** @return list<ReactionCount> in the curated set's order, only emojis someone chose */
    public function counts(ReactableType $type, int $id): array
    {
        $item = $this->counts[self::key($type, $id)] ?? [];
        $counts = [];

        foreach (Reaction::cases() as $reaction) {
            if (isset($item[$reaction->value])) {
                [$count, $chosen] = $item[$reaction->value];
                $counts[] = new ReactionCount($reaction, $count, $chosen);
            }
        }

        return $counts;
    }

    public function mine(ReactableType $type, int $id): ?Reaction
    {
        return $this->mine[self::key($type, $id)] ?? null;
    }

    private static function key(ReactableType $type, int $id): string
    {
        return $type->value.':'.$id;
    }
}
