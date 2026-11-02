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
use Modules\BuildingGroup\Feed\GroupFeed;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Livewire\Concerns\InWorkspace;
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
 * impersonating (`viewImages`).
 */
#[Layout('layouts::app')]
#[Title('Building Group')]
#[RequiresFeature(Feature::BuildingGroup)]
final class GroupShow extends Component
{
    use InWorkspace, RunsGroupFeed;

    #[Locked]
    public int $groupId;

    public function mount(OrganizationContext $context, int $group): void
    {
        $this->enterWorkspace($context);
        $this->groupId = $group;
        $this->authorize('view', $this->group());
    }

    public function render(GroupFeed $feed, GroupList $list, OrganizationContext $context): View
    {
        $group = $this->group();
        $this->authorize('view', $group);
        $user = $this->user();
        $images = $user->can('viewImages', $group);
        [$posts, $more] = $feed->page($group, $user, $this->limit, $images);
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

    private function group(): BuildingGroup
    {
        return BuildingGroup::query()->findOrFail($this->groupId);
    }
}
