<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Live;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "This group's feed changed" (P07-03), broadcast through Reverb to the
 * group's private channel `group.{id}` (Live\GroupChannel decides who may
 * listen). Sent at once, not queued, so open pages hear it within a second;
 * it holds the new feed version only, never words, names or ids of posts.
 */
final class FeedChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $groupId,
        public readonly string $version,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(LiveFeed::channel($this->groupId))];
    }

    public function broadcastAs(): string
    {
        return 'feed.changed';
    }

    /** @return array{version: string} */
    public function broadcastWith(): array
    {
        return ['version' => $this->version];
    }
}
