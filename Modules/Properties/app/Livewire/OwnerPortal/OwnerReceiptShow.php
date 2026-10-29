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
 * A receipt for a payment on the owner's bills, /owner/receipts/{id}
 * (P03-16): an on-screen page built from what was kept at issue — number,
 * date, amount, how the tenant paid, the owner's bills it settled, valid
 * or voided — never the issued PDF, no payment reference and no bank
 * details. Found only when it settled one of the owner's bills. Anything
 * else answers 404, like a missing record. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Receipt')]
final class OwnerReceiptShow extends Component implements ReadOnlyOwnerPage
{
    #[Locked]
    public int $receiptId;

    public function mount(int $receipt): void
    {
        $this->receiptId = $receipt;
    }

    public function render(OwnerPortalRecords $records): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('properties::livewire.owner-portal.receipt-show', [
            'page' => $records->receiptPage($user, $this->receiptId) ?? abort(404),
        ]);
    }
}
