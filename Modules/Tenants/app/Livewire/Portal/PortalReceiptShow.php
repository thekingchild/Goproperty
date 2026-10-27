<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\PortalPayments;
use Modules\Tenants\Portal\PortalReceiptPage;

/**
 * One of my receipts, /portal/receipts/{receipt} (P03-11b; UX_DESIGN.md
 * § 1 "trust signals": the QR/verify link and "confirmed by"): the agency's
 * branding as issued, the number, amount, how and when it was paid, how it
 * was confirmed and by whom, the bills it paid, the verify link anyone can
 * check, and — unless GoProperty staff are impersonating — the receipt as
 * a web page, its PDF as kept, and once voided the VOID copy.
 *
 * The receipt is looked up among the user's own on every request
 * (Portal\PortalPayments), so another tenant's or organization's receipt
 * answers 404. Read-only.
 */
#[Layout('layouts::app')]
#[Title('Receipt')]
final class PortalReceiptShow extends Component
{
    #[Locked]
    public int $receiptId;

    public function mount(PortalPayments $payments, int $receipt): void
    {
        $this->receiptId = $receipt;
        $this->page($payments);
    }

    public function render(PortalPayments $payments): View
    {
        return view('tenants::livewire.portal.receipt-show', ['page' => $this->page($payments)]);
    }

    private function page(PortalPayments $payments): PortalReceiptPage
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $page = $payments->receipt($user, $this->receiptId);
        abort_if($page === null, 404);

        return $page;
    }
}
