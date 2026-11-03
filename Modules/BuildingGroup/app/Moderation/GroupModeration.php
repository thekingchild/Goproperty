<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Moderation;

use App\Models\User;
use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\MemberType;
use Modules\BuildingGroup\Enums\ModerationAction;
use Modules\BuildingGroup\Enums\ModerationTarget;
use Modules\BuildingGroup\Enums\MuteLength;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\ReportStatus;
use Modules\BuildingGroup\Groups\GroupSettings;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\BuildingGroup\Models\GroupModerationEvent;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Posts\GroupRefused;
use Modules\BuildingGroup\Posts\ShownComments;

/**
 * What moderators do in a Building Group (ARCHITECTURE.md § 6.8 "How
 * moderation is built"; P07-04, docs/plans/P07-subtasks.md defaults
 * 39–51), in the
 * group's organization's context. The only writer of
 * `group_moderation_events`, and of the moderation columns: posts' and
 * comments' `status` and posts' `pinned_at`, members' `muted_until`, the
 * group's `settings`.
 *
 * Who: Policies\BuildingGroupPolicy — `moderate` (hide, show again, pin,
 * unpin, dismiss reports, settings), `removeContent` (remove: refused
 * while GoProperty staff impersonate) and `mute`; all need
 * `building_groups.moderate`, the group in the member's buildings and the
 * group open, and are refused while the organization is suspended.
 *
 * What:
 * - hiding a post or comment takes it away from members (the author too)
 *   and keeps it for moderators; showing it again puts it back as it was.
 *   Removing is final for members (it cannot be shown again) and also
 *   keeps it, photos included, for moderators. Both need a reason, unpin
 *   a post, and resolve the item's open reports as actioned. A hidden or
 *   removed post's comments vanish with it; a hidden or removed comment's
 *   replies with it;
 * - pinning puts a published post at the top of the feed for everyone,
 *   at most 3 a group (the newest pin first);
 * - muting a tenant (1, 7 or 30 days, with a reason) lets them read but
 *   not write or report until then; unmuting ends it now. Members of
 *   staff are not muted (their roles decide);
 * - dismissing closes the item's open reports with nothing changed;
 * - settings: whether tenants start posts and whether posts take photos.
 *
 * Each change runs in DB::transaction(…, 3) with its rows locked (the
 * post for a post or comment, the group for a pin or settings, the member
 * row for a mute) and writes one `group_moderation_events` row (who, what,
 * why) and an audit entry (log `building_group`, ids only: never the
 * reason or the words), then nudges open pages (Live\LiveFeed). Asking for
 * what is already so (hiding a hidden post, pinning a pinned one) changes
 * nothing and writes nothing.
 */
final readonly class GroupModeration
{
    public const string LOG = 'building_group';

    /** Pinned posts a group may have at once. */
    public const int MAX_PINNED = 3;

    public const int REASON_MAX = 500;

    public function __construct(
        private AbuseReports $reports,
        private PlanGate $plans,
        private OrganizationContext $context,
        private LiveFeed $live,
    ) {}

    /** @throws ValidationException */
    public function hide(User $by, ReactableType $type, int $id, ?string $reason): void
    {
        $this->changeStatus($by, $type, $id, ModerationAction::Hide, self::reason($reason));
    }

    /** @throws ValidationException */
    public function unhide(User $by, ReactableType $type, int $id): void
    {
        $this->changeStatus($by, $type, $id, ModerationAction::Unhide, null);
    }

    /** @throws ValidationException */
    public function remove(User $by, ReactableType $type, int $id, ?string $reason): void
    {
        $this->changeStatus($by, $type, $id, ModerationAction::Remove, self::reason($reason));
    }

    /** @throws ValidationException */
    public function pin(User $by, int $postId): void
    {
        $this->changePin($by, $postId, true);
    }

    /** @throws ValidationException */
    public function unpin(User $by, int $postId): void
    {
        $this->changePin($by, $postId, false);
    }

    /**
     * Mutes the tenant behind the member row until now + $length.
     *
     * @throws ValidationException
     */
    public function mute(User $by, BuildingGroup $group, int $memberId, MuteLength $length, ?string $reason): void
    {
        Gate::forUser($by)->authorize('mute', $group);
        $reason = self::reason($reason);

        DB::transaction(function () use ($by, $group, $memberId, $length, $reason): void {
            $member = $this->lockedMember($group, $memberId);
            $until = CarbonImmutable::now()->addDays($length->value);
            $member->muted_until = $until;
            $member->save();

            $this->record($by, $group, ModerationAction::MuteMember, ModerationTarget::Member, $member->id, $reason, [
                'tenant_profile_id' => $member->tenant_profile_id,
                'days' => $length->value,
                'muted_until' => $until->toIso8601String(),
            ], 'group.member_muted', 'Member muted in the Building Group');
        }, 3);
    }

    /** @throws ValidationException */
    public function unmute(User $by, BuildingGroup $group, int $memberId): void
    {
        Gate::forUser($by)->authorize('mute', $group);

        DB::transaction(function () use ($by, $group, $memberId): void {
            $member = $this->lockedMember($group, $memberId);

            if ($member->muted_until === null || ! $member->muted_until->isAfter(CarbonImmutable::now())) {
                return;
            }

            $member->muted_until = null;
            $member->save();

            $this->record($by, $group, ModerationAction::UnmuteMember, ModerationTarget::Member, $member->id, null, [
                'tenant_profile_id' => $member->tenant_profile_id,
            ], 'group.member_unmuted', 'Member unmuted in the Building Group');
        }, 3);
    }

    /**
     * Closes the item's open reports with nothing changed.
     *
     * @throws ValidationException
     */
    public function dismissReports(User $by, ReactableType $type, int $id): void
    {
        [$post] = $this->item($type, $id);
        $group = $this->groupOf($post);
        Gate::forUser($by)->authorize('moderate', $group);

        DB::transaction(function () use ($by, $type, $id, $group): void {
            $this->lockedPost($this->item($type, $id)[0]->id);
            $resolved = $this->reports->resolve($type, $id, ReportStatus::Dismissed, $by);

            if ($resolved === 0) {
                return;
            }

            $this->record($by, $group, ModerationAction::DismissReports, ModerationTarget::of($type), $id, null, [
                'reports_dismissed' => $resolved,
            ], 'group.reports_dismissed', 'Reports dismissed in the Building Group');
        }, 3);
    }

    /** @throws ValidationException */
    public function changeSettings(User $by, BuildingGroup $group, bool $membersPost, bool $images): void
    {
        Gate::forUser($by)->authorize('moderate', $group);

        DB::transaction(function () use ($by, $group, $membersPost, $images): void {
            $group = $this->lockedGroup($group->id);
            $before = GroupSettings::of($group);

            if ($before->membersPost === $membersPost && $before->images === $images) {
                return;
            }

            $group->forceFill(['settings' => [...($group->settings ?? []), 'members_post' => $membersPost, 'images' => $images]])->save();

            $this->record($by, $group, ModerationAction::ChangeSettings, ModerationTarget::Group, $group->id, null, [
                'before' => ['members_post' => $before->membersPost, 'images' => $before->images],
                'after' => ['members_post' => $membersPost, 'images' => $images],
            ], 'group.settings_changed', 'Building Group settings changed');
        }, 3);
    }

    /** @throws ValidationException */
    private function changeStatus(User $by, ReactableType $type, int $id, ModerationAction $action, ?string $reason): void
    {
        [$post] = $this->item($type, $id);
        $group = $this->groupOf($post);
        Gate::forUser($by)->authorize($action === ModerationAction::Remove ? 'removeContent' : 'moderate', $group);

        DB::transaction(function () use ($by, $type, $id, $action, $reason, $group): void {
            $post = $this->lockedPost($this->item($type, $id)[0]->id);
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            $this->ensureOpen($group->id);
            $item = $type === ReactableType::Post ? $post : $this->item($type, $id)[1];

            $to = match ($action) {
                ModerationAction::Hide => $item->status === PostStatus::Published ? PostStatus::Hidden : null,
                ModerationAction::Unhide => $item->status === PostStatus::Hidden ? PostStatus::Published : null,
                default => $item->status !== PostStatus::Removed ? PostStatus::Removed : null,
            };

            if ($to === null) {
                if ($action === ModerationAction::Unhide && $item->status === PostStatus::Removed) {
                    throw GroupRefused::because(__('A removed post or comment cannot be shown again.'), 'moderation');
                }

                return;
            }

            $from = $item->status;
            $wasPinned = $item instanceof GroupPost && $item->pinned_at !== null;
            $item->status = $to;

            if ($item instanceof GroupPost && $to !== PostStatus::Published) {
                $item->pinned_at = null;
            }

            $item->save();

            if ($item instanceof GroupComment) {
                $post->comment_count = ShownComments::count($post);
                $post->save();
            }

            $resolved = $to === PostStatus::Published ? 0 : $this->reports->resolve($type, $id, ReportStatus::Actioned, $by);
            $noun = $type === ReactableType::Post ? 'post' : 'comment';

            $this->record($by, $group, $action, ModerationTarget::of($type), $id, $reason, array_filter([
                'from' => $from->value,
                'to' => $to->value,
                'group_post_id' => $item instanceof GroupComment ? $post->id : null,
                'unpinned' => $wasPinned ?: null,
                'reports_actioned' => $resolved ?: null,
            ], static fn (mixed $value): bool => $value !== null), "group.{$noun}_".match ($action) {
                ModerationAction::Hide => 'hidden',
                ModerationAction::Unhide => 'unhidden',
                default => 'removed',
            }, match ($action) {
                ModerationAction::Hide => ucfirst($noun).' hidden by a moderator in the Building Group',
                ModerationAction::Unhide => ucfirst($noun).' shown again by a moderator in the Building Group',
                default => ucfirst($noun).' removed by a moderator from the Building Group',
            });
        }, 3);
    }

    /** @throws ValidationException */
    private function changePin(User $by, int $postId, bool $pin): void
    {
        [$post] = $this->item(ReactableType::Post, $postId);
        $group = $this->groupOf($post);
        Gate::forUser($by)->authorize('moderate', $group);

        DB::transaction(function () use ($by, $postId, $pin, $group): void {
            $group = $this->lockedGroup($group->id);
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            $post = $this->lockedPost($postId);

            if ($pin && $post->status !== PostStatus::Published) {
                throw GroupRefused::because(__('Only a post members can see can be pinned.'), 'moderation');
            }

            if (($post->pinned_at !== null) === $pin) {
                return;
            }

            if ($pin && GroupPost::query()->where('building_group_id', $group->id)->whereNotNull('pinned_at')->count() >= self::MAX_PINNED) {
                throw GroupRefused::because(__('A group can have :max pinned posts. Unpin one first.', ['max' => self::MAX_PINNED]), 'moderation');
            }

            $post->pinned_at = $pin ? CarbonImmutable::now() : null;
            $post->save();

            $this->record($by, $group, $pin ? ModerationAction::Pin : ModerationAction::Unpin, ModerationTarget::Post, $post->id, null, [],
                $pin ? 'group.post_pinned' : 'group.post_unpinned',
                $pin ? 'Post pinned in the Building Group' : 'Post unpinned in the Building Group');
        }, 3);
    }

    /**
     * Writes the trail row and the audit entry, and nudges open pages once
     * the change commits.
     *
     * @param  array<string, mixed>  $details
     */
    private function record(User $by, BuildingGroup $group, ModerationAction $action, ModerationTarget $target, int $targetId, ?string $reason, array $details, string $event, string $description): void
    {
        $row = new GroupModerationEvent;
        $row->building_group_id = $group->id;
        $row->actor_user_id = $by->id;
        $row->action = $action;
        $row->target_type = $target;
        $row->target_id = $targetId;
        $row->reason = $reason;
        $row->details = $details === [] ? null : $details;
        $row->save();

        activity(self::LOG)
            ->event($event)
            ->performedOn($row)
            ->causedBy($by)
            ->withProperties([
                'building_group_id' => $group->id,
                'property_id' => $group->property_id,
                'target_type' => $target->value,
                'target_id' => $targetId,
                'group_moderation_event_id' => $row->id,
                ...$details,
            ])
            ->log($description);

        $this->live->changed($group->id);
    }

    /** @throws ValidationException when it is missing or too long */
    private static function reason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if ($reason === '') {
            throw ValidationException::withMessages(['moderation' => __('Say why, for the record. Members do not see it.')]);
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['moderation' => __('Keep the reason to :max characters.', ['max' => self::REASON_MAX])]);
        }

        return $reason;
    }

    /**
     * The post and the item (the post, or the comment) as moderators see
     * them: any status, never an author's own removal.
     *
     * @return array{0: GroupPost, 1: GroupPost|GroupComment}
     */
    private function item(ReactableType $type, int $id): array
    {
        if ($type === ReactableType::Post) {
            $post = GroupPost::query()->find($id);

            return $post instanceof GroupPost ? [$post, $post] : throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
        }

        $comment = GroupComment::query()->find($id);
        $post = $comment instanceof GroupComment ? GroupPost::query()->find($comment->group_post_id) : null;

        if (! $comment instanceof GroupComment || ! $post instanceof GroupPost) {
            throw GroupRefused::because(__('That comment is no longer there.'), 'moderation');
        }

        return [$post, $comment];
    }

    private function groupOf(GroupPost $post): BuildingGroup
    {
        return BuildingGroup::query()->find($post->building_group_id)
            ?? throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
    }

    private function lockedPost(int $postId): GroupPost
    {
        return GroupPost::query()->whereKey($postId)->lockForUpdate()->first()
            ?? throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
    }

    private function lockedGroup(int $groupId): BuildingGroup
    {
        $group = BuildingGroup::query()->whereKey($groupId)->lockForUpdate()->first();

        if (! $group instanceof BuildingGroup || ! $group->is_active) {
            throw GroupRefused::because(__('This group is closed: its building is no longer managed here.'), 'moderation');
        }

        return $group;
    }

    /** The group closed while waiting (a handover, an archived building): the policy's rule, again under the lock. */
    private function ensureOpen(int $groupId): void
    {
        if (! BuildingGroup::query()->whereKey($groupId)->where('is_active', true)->exists()) {
            throw GroupRefused::because(__('This group is closed: its building is no longer managed here.'), 'moderation');
        }
    }

    private function lockedMember(BuildingGroup $group, int $memberId): GroupMember
    {
        $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
        $this->lockedGroup($group->id);
        $member = GroupMember::query()->where('building_group_id', $group->id)->whereKey($memberId)->lockForUpdate()->first();

        if (! $member instanceof GroupMember || $member->member_type !== MemberType::Tenant || $member->left_at !== null) {
            throw GroupRefused::because(__('That tenant is no longer a member of this group.'), 'moderation');
        }

        return $member;
    }
}
