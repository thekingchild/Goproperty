<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Live;

use App\Hosting\Realtime;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps open group pages current (P07-03; ARCHITECTURE.md § 6.8 "How live
 * updates are built"). Every write to a group's feed (Posts\GroupPosts:
 * a post, comment, reply, reaction or removal) calls changed() once it
 * has committed: the group's feed version moves on, and on a VPS
 * (App\Hosting\Realtime::usesWebsockets()) Reverb gets a FeedChanged nudge
 * on the private channel `group.{id}`. A nudge carries no words: the page
 * asks for its feed again through Livewire, which checks who is asking.
 *
 * Pages poll on every profile (every 15 s on shared hosting, 60 s behind
 * Reverb) and re-render only when the version they last showed has moved,
 * so a quiet group costs a cache read per poll; polling pauses in a hidden
 * tab and after IDLE_SECONDS without activity. The version lives in the
 * cache: when the cache is cleared every page simply renders once more.
 */
final readonly class LiveFeed
{
    /**
     * A page stops polling after this long without the person touching it
     * (and while its tab is hidden), so a forgotten tab neither loads the
     * host nor keeps the session alive; it catches up as soon as they are back.
     */
    public const int IDLE_SECONDS = 30 * 60;

    public function __construct(
        private Cache $cache,
        private Realtime $realtime,
        private Dispatcher $events,
    ) {}

    /** The private channel of a group's page (without Pusher's `private-` prefix). */
    public static function channel(int $groupId): string
    {
        return 'group.'.$groupId;
    }

    /** The group's feed version now: it changes with every write. */
    public function version(int $groupId): string
    {
        $version = $this->cache->get(self::key($groupId));

        return is_string($version) ? $version : '';
    }

    /** The group's feed changed: after the commit, move its version on and nudge its open pages. */
    public function changed(int $groupId): void
    {
        DB::afterCommit(function () use ($groupId): void {
            $version = (string) Str::ulid();
            $this->cache->forever(self::key($groupId), $version);

            if (! $this->realtime->usesWebsockets()) {
                return;
            }

            $event = new FeedChanged($groupId, $version);
            // The writer's own page re-renders with its answer; its other tabs still hear it.
            $event->dontBroadcastToCurrentUser();

            try {
                $this->events->dispatch($event);
            } catch (Throwable $failed) {
                // Reverb down or unreachable: the write stands and pages catch up at their next poll.
                report($failed);
            }
        });
    }

    private static function key(int $groupId): string
    {
        return 'building-group:feed-version:'.$groupId;
    }
}
