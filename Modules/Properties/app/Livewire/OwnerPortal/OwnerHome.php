<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire\OwnerPortal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Properties\Contracts\ReadOnlyOwnerPage;
use Modules\Properties\OwnerPortal\OwnerPortalRecords;

/**
 * The owner portal's home, /owner (P03-15; ARCHITECTURE.md § 3): for every
 * organization that manages buildings of the signed-in owner, in that
 * organization's current colours and logo, the owner's buildings with
 * their units and occupancy. Read through OwnerPortal\OwnerPortalRecords
 * (the "linked to my user" path, each owner record checked by
 * OwnerProfilePolicy::viewAsOwner inside its organization). Someone who
 * owns nothing here sees an empty state. Read-only: no actions.
 */
#[Layout('layouts::app')]
#[Title('My properties')]
final class OwnerHome extends Component implements ReadOnlyOwnerPage
{
    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.home', ['agencies' => $records->home($user)]);
    }
}
