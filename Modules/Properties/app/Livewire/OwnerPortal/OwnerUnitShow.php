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
 * One of the owner's units, /owner/units/{id} (P03-16): its lease history
 * as a timeline — every lease the agency activated on it, newest first,
 * with the tenant, the moves (moved in, renewed, rent changed, ended
 * early, moved out); drafts never. Anything else answers 404, like a
 * missing record. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Unit')]
final class OwnerUnitShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $unitId;

    public function mount(int $unit): void
    {
        $this->unitId = $unit;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.unit-show', [
            'page' => $records->unitPage($user, $this->unitId) ?? abort(404),
        ]);
    }
}
