<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire\OwnerPortal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;
use Modules\Properties\OwnerPortal\OwnerPortalRecords;

/**
 * One of the owner's buildings, /owner/buildings/{id} (P03-15): its units
 * with their occupancy, active lease and a link to each unit's lease
 * history (P03-16), and its latest bills with their status. Found among the signed-in owner's own
 * buildings on every render (OwnerPortalRecords::buildingPage), so another
 * owner's or organization's building answers 404, like a missing one.
 * Read-only.
 */
#[Layout('layouts::app')]
#[Title('Building')]
final class OwnerBuildingShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $buildingId;

    public function mount(int $building): void
    {
        $this->buildingId = $building;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.building-show', [
            'page' => $records->buildingPage($user, $this->buildingId) ?? abort(404),
        ]);
    }
}
