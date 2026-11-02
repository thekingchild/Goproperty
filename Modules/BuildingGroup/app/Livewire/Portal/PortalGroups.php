<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\BuildingGroup\Portal\TenantGroups;

/**
 * Building Group, /portal/groups (P07-02): the groups of the buildings the
 * tenant lives in, across the agencies they rent from (Portal\TenantGroups:
 * a current member, the agency's plan including Building Group), each in
 * its agency's colours with its posts and latest activity. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Building Group')]
final class PortalGroups extends Component
{
    public function render(TenantGroups $groups): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('buildinggroup::livewire.portal.groups', [
            'groups' => $groups->groups($user),
        ]);
    }
}
