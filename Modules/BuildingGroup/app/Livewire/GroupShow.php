<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire;

use App\Plans\Feature;
use App\Plans\RequiresFeature;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\MuteLength;
use Modules\BuildingGroup\Enums\ReportReason;
use Modules\BuildingGroup\Feed\GroupFeed;
use Modules\BuildingGroup\Feed\MutedMembers;
use Modules\BuildingGroup\Feed\ReportQueue;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Livewire\Concerns\InWorkspace;
use Modules\BuildingGroup\Livewire\Concerns\ModeratesGroup;
use Modules\BuildingGroup\Livewire\Concerns\RunsGroupFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupMember;

/**
 * A Building Group, /o/{organization}/groups/{group} (P07-02): the post
 * form, then the feed (Feed\GroupFeed) with comments, replies and
 * reactions; the members of staff write as the agency (AuthorKind::Staff).
 * The group id is locked and the group loaded through the organization
 * scope and checked (`view`: another building's group answers 404 to an
 * assigned agent) on every request. A closed group (its building archived
 * or handed over) is read-only. Images show unless GoProperty staff are
 * impersonating (`viewImages`). Kept current while open (P07-03,
 * RunsGroupFeed::refreshFeed()).
 *
 * Moderators (`viewModeration`, P07-04; Concerns\ModeratesGroup) also see
 * the open reports, the muted members and the group's settings above the
 * feed, and in it the posts and comments hidden or removed, with their
 * trail and the actions their permissions allow.
 */
#[Layout('layouts::app')]
#[Title('Building Group')]
#[RequiresFeature(Feature::BuildingGroup)]
final class GroupShow extends Component
{
    use InWorkspace, ModeratesGroup, RunsGroupFeed;

    #[Locked]
    public int $groupId;

    public function mount(OrganizationContext $context, int $group): void
    {
        $this->enterWorkspace($context);
        $this->groupId = $group;
        $group = $this->group();
        $this->authorize('view', $group);
        $this->loadSettings($group);
    }

    public function render(GroupFeed $feed, GroupList $list, OrganizationContext $context, ReportQueue $reports, MutedMembers $muted): View
    {
        $group = $this->group();
        $this->authorize('view', $group);
        $user = $this->user();
        $images = $user->can('viewImages', $group);
        $moderator = $user->can('viewModeration', $group);
        $live = $this->goLive();
        [$posts, $more] = $feed->page($group, $user, $this->limit, $images, $moderator);
        $this->keepBoxes($posts);

        return view('buildinggroup::livewire.group-show', [
            'organization' => $context->get(),
            'group' => $group,
            'name' => $list->nameOf($group),
            'members' => GroupMember::query()->where('building_group_id', $group->id)->current()->count(),
            'posts' => $posts,
            'more' => $more,
            'showImages' => $images,
            'canPost' => $user->can('post', $group),
            'canAttach' => $user->can('attachImages', $group),
            'canComment' => $user->can('comment', $group),
            'canReact' => $user->can('react', $group),
            'canReport' => ! $moderator && $user->can('report', $group),
            'isModerator' => $moderator,
            'canModerate' => $moderator && $user->can('moderate', $group),
            'canRemove' => $moderator && $user->can('removeContent', $group),
            'canMute' => $moderator && $user->can('mute', $group),
            'reported' => $moderator ? $reports->open($group, $user) : [],
            'muted' => $moderator ? $muted->of($group) : [],
            'reasons' => ReportReason::cases(),
            'muteLengths' => MuteLength::cases(),
            'live' => $live,
        ]);
    }

    protected function author(): AuthorKind
    {
        return AuthorKind::Staff;
    }

    protected function inGroup(Closure $callback): mixed
    {
        $group = $this->group();
        $this->authorize('view', $group);

        return $callback($group, $this->user());
    }

    protected function leaveTo(): string
    {
        return route('workspace.groups.index', app(OrganizationContext::class)->get());
    }

    private function group(): BuildingGroup
    {
        return BuildingGroup::query()->findOrFail($this->groupId);
    }
}
