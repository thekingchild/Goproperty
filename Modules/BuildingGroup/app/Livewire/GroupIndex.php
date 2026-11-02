<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Plans\Feature;
use App\Plans\RequiresFeature;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Livewire\Concerns\InWorkspace;
use Modules\BuildingGroup\Models\BuildingGroup;

/**
 * Building Groups, /o/{organization}/groups (P07-02): one per building the
 * member sees (an assigned agent: their buildings), open ones first, with
 * the tenants who are members now, the posts and the latest activity; each
 * opens its page. Read through Lists\GroupList, which the export uses too.
 * Needs the plan's Building Group (the route's `plan:building_group` and
 * BuildingGroupPolicy::viewAny).
 */
#[Layout('layouts::app')]
#[Title('Building Groups')]
#[RequiresFeature(Feature::BuildingGroup)]
final class GroupIndex extends Component
{
    use InWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    public function mount(OrganizationContext $context): void
    {
        $this->enterWorkspace($context);
        $this->authorize('viewAny', BuildingGroup::class);
    }

    public function render(OrganizationContext $context, GroupList $list): View
    {
        $this->authorize('viewAny', BuildingGroup::class);
        $groups = $list->query($this->user(), [])->paginate(self::PER_PAGE);
        $list->remember($groups->items());

        return view('buildinggroup::livewire.group-index', [
            'organization' => $context->get(),
            'groups' => $groups,
            'list' => $list,
        ]);
    }

    protected function listExports(): array
    {
        return ['building_groups' => []];
    }
}
