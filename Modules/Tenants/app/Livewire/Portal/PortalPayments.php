<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire\Portal;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Tenants\Portal\PortalPayments as Payments;

/**
 * My payments, /portal/payments (P03-11b): per agency, the proofs of payment
 * the user sent (or a member uploaded for them) and where each stands —
 * waiting for the agency, accepted (with its receipt), rejected with the
 * agency's reason — and the receipts issued to them, each opening its own
 * page. Read through Portal\PortalPayments; read-only.
 */
#[Layout('layouts::app')]
#[Title('My payments')]
final class PortalPayments extends Component
{
    public function render(Payments $payments): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return view('tenants::livewire.portal.payments', ['agencies' => $payments->of($user)]);
    }
}
