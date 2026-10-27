<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\PortalBillPage;
use Modules\Tenants\Portal\PortalRentals;

/**
 * One of my bills, /portal/bills/{bill} (P03-11a): the agency's branding as
 * the bill was issued, the status, the amount to pay and its due date, the
 * lines, and how to pay — the agency's bank account with the payment
 * reference for the narration (ONBOARDING_COMPLIANCE.md § 4.1), the online
 * payment options (Billing's Contracts\PaymentOptions, e.g. P03-08's
 * Paystack) and the public pay page (/pay/{reference}) to share — plus the
 * invoice as a web page when TenantProfilePolicy::viewInvoiceAsTenant
 * allows.
 *
 * The bill is looked up among the user's own tenant profiles in their
 * organizations on every request (Portal\PortalRentals::billPage), so
 * another tenant's or organization's bill answers 404 like a missing one.
 * Read-only: no actions.
 */
#[Layout('layouts::app')]
#[Title('Bill')]
final class PortalBillShow extends Component
{
    #[Locked]
    public int $billId;

    public function mount(PortalRentals $rentals, int $bill): void
    {
        $this->billId = $bill;
        $this->page($rentals);
    }

    public function render(PortalRentals $rentals): View
    {
        return view('tenants::livewire.portal.bill-show', ['page' => $this->page($rentals)]);
    }

    private function page(PortalRentals $rentals): PortalBillPage
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $page = $rentals->billPage($user, $this->billId);
        abort_if($page === null, 404);

        return $page;
    }
}
