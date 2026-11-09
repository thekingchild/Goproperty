<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use App\Models\User;
use Modules\BuildingGroup\Enums\ModerationTarget;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Models\GroupModerationEvent;
use Modules\BuildingGroup\Models\GroupReport;

/**
 * What the feed adds about moderation for the page's posts and comments
 * (P07-04): for moderators, each item's trail (who hid, showed again,
 * removed, pinned or unpinned it, when and why) and its open reports; for
 * everyone, whether the viewer has reported it.
 *
 * @internal
 */
final readonly class ModerationView
{
    /**
     * @param  array<string, list<GroupModerationEvent>>  $events  "target type:id" => oldest first (by item, once: P09-03)
     * @param  array<string, int>  $openReports  "type:id" => count
     * @param  array<string, true>  $mine  "type:id" reported by the viewer
     * @param  array<int, string>  $actors  user id => name
     */
    private function __construct(
        private array $events,
        private array $openReports,
        private array $mine,
        private array $actors,
    ) {}

    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $commentIds
     */
    public static function load(array $postIds, array $commentIds, User $viewer, bool $moderator): self
    {
        $items = static function ($query, string $typeColumn, string $idColumn, string $postType, string $commentType) use ($postIds, $commentIds): void {
            $query->where(static function ($query) use ($typeColumn, $idColumn, $postType, $commentType, $postIds, $commentIds): void {
                $query->where(static fn ($posts) => $posts->where($typeColumn, $postType)->whereIn($idColumn, $postIds));

                if ($commentIds !== []) {
                    $query->orWhere(static fn ($comments) => $comments->where($typeColumn, $commentType)->whereIn($idColumn, $commentIds));
                }
            });
        };

        $mine = [];

        foreach (GroupReport::query()->where('reporter_user_id', $viewer->id)
            ->tap(static fn ($query) => $items($query, 'reportable_type', 'reportable_id', ReactableType::Post->value, ReactableType::Comment->value))
            ->get(['reportable_type', 'reportable_id']) as $report) {
            $mine[$report->reportable_type->value.':'.$report->reportable_id] = true;
        }

        if (! $moderator) {
            return new self([], [], $mine, []);
        }

        $events = GroupModerationEvent::query()
            ->tap(static fn ($query) => $items($query, 'target_type', 'target_id', ModerationTarget::Post->value, ModerationTarget::Comment->value))
            ->orderBy('id')
            ->get();

        $open = [];

        foreach (GroupReport::query()->open()
            ->tap(static fn ($query) => $items($query, 'reportable_type', 'reportable_id', ReactableType::Post->value, ReactableType::Comment->value))
            ->get(['reportable_type', 'reportable_id']) as $report) {
            $key = $report->reportable_type->value.':'.$report->reportable_id;
            $open[$key] = ($open[$key] ?? 0) + 1;
        }

        $actorIds = array_values(array_unique(array_filter($events->pluck('actor_user_id')->all(), static fn (mixed $id): bool => $id !== null)));
        /** @var array<int, string> $actors */
        $actors = $actorIds === [] ? [] : User::query()->whereIn('id', $actorIds)->pluck('name', 'id')->all();

        $byItem = [];

        foreach ($events as $event) {
            $byItem[$event->target_type->value.':'.$event->target_id][] = $event;
        }

        return new self($byItem, $open, $mine, $actors);
    }

    /** @return list<TrailEntry> oldest first */
    public function trail(ReactableType $type, int $id): array
    {
        return array_map(fn (GroupModerationEvent $event): TrailEntry => new TrailEntry(
            action: $event->action,
            actor: $event->actor_user_id !== null && isset($this->actors[$event->actor_user_id]) ? $this->actors[$event->actor_user_id] : __('A former member of staff'),
            at: $event->created_at,
            reason: $event->reason,
        ), $this->events[ModerationTarget::of($type)->value.':'.$id] ?? []);
    }

    public function openReports(ReactableType $type, int $id): int
    {
        return $this->openReports[$type->value.':'.$id] ?? 0;
    }

    public function reportedByViewer(ReactableType $type, int $id): bool
    {
        return isset($this->mine[$type->value.':'.$id]);
    }
}
