<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire\Concerns;

use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\BuildingGroup\Enums\MuteLength;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Groups\GroupSettings;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Moderation\GroupModeration;
use Modules\BuildingGroup\Posts\GroupRefused;

/**
 * Moderation on the workspace's group page (P07-04): hide, show again,
 * remove, pin and unpin posts and comments, mute and unmute their tenant
 * authors, dismiss reports, and the group's settings. Hiding, removing and
 * muting first open a short form under the item asking why (and, for a
 * mute, how long). Every action goes through Moderation\GroupModeration,
 * which checks the policy again; items are looked up in the page's group
 * at any status but an author's own removal. A refusal shows under
 * `moderation`.
 *
 * @phpstan-require-extends Component
 */
trait ModeratesGroup
{
    /** The moderation form open, and where: "hide|group_post:12|feed", "remove|…|queue", "mute|…". */
    public ?string $acting = null;

    public string $reason = '';

    public int $muteDays = MuteLength::Week->value;

    public bool $settingMembersPost = true;

    public bool $settingImages = true;

    public function startModeration(string $action, string $type, int $id, string $where = 'feed'): void
    {
        if (! in_array($action, ['hide', 'remove', 'mute'], true) || ReactableType::tryFrom($type) === null || ! in_array($where, ['feed', 'queue'], true)) {
            throw GroupRefused::because(__('Choose one of the actions shown.'), 'moderation');
        }

        $this->acting = $action.'|'.$type.':'.$id.'|'.$where;
        $this->reason = '';
        $this->muteDays = MuteLength::Week->value;
        $this->resetValidation('moderation');
    }

    public function cancelModeration(): void
    {
        $this->acting = null;
        $this->resetValidation('moderation');
    }

    public function confirmModeration(GroupModeration $moderation): void
    {
        [$action, $key] = array_pad(explode('|', (string) $this->acting, 3), 2, '');
        [$type, $id] = self::itemKey($key, 'moderation');

        $this->inGroup(function (BuildingGroup $group, User $user) use ($moderation, $action, $type, $id): void {
            $this->moderatedIn($group, $type, $id);

            match ($action) {
                'hide' => $moderation->hide($user, $type, $id, $this->reason),
                'remove' => $moderation->remove($user, $type, $id, $this->reason),
                'mute' => $moderation->mute($user, $group, $this->authorMemberIn($group, $type, $id), MuteLength::tryFrom($this->muteDays) ?? throw GroupRefused::because(__('Choose how long.'), 'moderation'), $this->reason),
                default => throw GroupRefused::because(__('Choose one of the actions shown.'), 'moderation'),
            };
        });

        $this->notice = match ($action) {
            'hide' => __('Hidden. Members no longer see it; you can show it again.'),
            'remove' => __('Removed. Members no longer see it; it stays here for moderators.'),
            default => __('Muted. They can read the group but not write in it until then.'),
        };
        $this->acting = null;
        $this->reason = '';
    }

    public function unhide(GroupModeration $moderation, string $type, int $id): void
    {
        $type = ReactableType::tryFrom($type) ?? throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->unhide($user, $type, $this->moderatedIn($group, $type, $id)));
        $this->notice = __('Shown again to members.');
    }

    public function pin(GroupModeration $moderation, int $postId): void
    {
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->pin($user, $this->moderatedIn($group, ReactableType::Post, $postId)));
        $this->notice = __('Pinned to the top of the group.');
    }

    public function unpin(GroupModeration $moderation, int $postId): void
    {
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->unpin($user, $this->moderatedIn($group, ReactableType::Post, $postId)));
        $this->notice = __('Unpinned.');
    }

    public function dismissReports(GroupModeration $moderation, string $type, int $id): void
    {
        $type = ReactableType::tryFrom($type) ?? throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->dismissReports($user, $type, $this->moderatedIn($group, $type, $id)));
        $this->notice = __('Reports dismissed; nothing was changed.');
    }

    public function unmute(GroupModeration $moderation, int $memberId): void
    {
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->unmute($user, $group, $memberId));
        $this->notice = __('Unmuted. They can write in the group again.');
    }

    public function saveSettings(GroupModeration $moderation): void
    {
        $this->moderating(fn (BuildingGroup $group, User $user) => $moderation->changeSettings($user, $group, $this->settingMembersPost, $this->settingImages));
        $this->notice = __('Group settings saved.');
    }

    /** Fills the settings form from the group (mount). */
    protected function loadSettings(BuildingGroup $group): void
    {
        $settings = GroupSettings::of($group);
        $this->settingMembersPost = $settings->membersPost;
        $this->settingImages = $settings->images;
    }

    /**
     * Runs $action in the group, showing a refusal under `moderation`.
     *
     * @throws ValidationException
     */
    private function moderating(Closure $action): void
    {
        try {
            $this->inGroup($action);
        } catch (ValidationException $refused) {
            $message = collect($refused->errors())->flatten()->first();

            throw ValidationException::withMessages(['moderation' => is_string($message) ? $message : __('That did not work. Try again.')]);
        }
    }

    /** The id of a post or comment of this group, at any status but an author's own removal. */
    private function moderatedIn(BuildingGroup $group, ReactableType $type, int $id): int
    {
        if ($type === ReactableType::Post) {
            return GroupPost::query()->where('building_group_id', $group->id)->find($id)->id
                ?? throw GroupRefused::because(__('That post is no longer there.'), 'moderation');
        }

        $comment = GroupComment::query()->find($id);

        if (! $comment instanceof GroupComment || ! GroupPost::query()->where('building_group_id', $group->id)->whereKey($comment->group_post_id)->exists()) {
            throw GroupRefused::because(__('That comment is no longer there.'), 'moderation');
        }

        return $comment->id;
    }

    /** The member row of the tenant who wrote the item. */
    private function authorMemberIn(BuildingGroup $group, ReactableType $type, int $id): int
    {
        $item = $type === ReactableType::Post ? GroupPost::query()->find($id) : GroupComment::query()->find($id);
        $tenantId = $item?->author_tenant_profile_id;
        $member = $tenantId === null ? null : GroupMember::query()->where('building_group_id', $group->id)->where('tenant_profile_id', $tenantId)->first();

        return $member->id ?? throw GroupRefused::because(__('Only a tenant of the building can be muted.'), 'moderation');
    }
}
