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
 * A bill of one of the owner's buildings, /owner/bills/{id} (P03-15): its
 * status, amounts, period, lines and the tenant's name as issued; no pay
 * instructions and no invoice (it carries the tenant's contact details).
 * Found among the bills of the signed-in owner's own buildings, so
 * anything else answers 404. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Bill')]
final class OwnerBillShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $billId;

    public function mount(int $bill): void
    {
        $this->billId = $bill;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.bill-show', [
            'page' => $records->billPage($user, $this->billId) ?? abort(404),
        ]);
    }
}
