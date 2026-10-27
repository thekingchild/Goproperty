<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Lists\BillParties;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;

/**
 * A bill, /o/{organization}/bills/{bill}: number, status (the OVERDUE
 * badge too), tenant, unit, period, due date, lines, what is paid and
 * outstanding, the payment reference for transfer narrations, and Void
 * with a reason while the bill asks for money and has no payment applied
 * (BillPolicy::void, then Bills\BillService, which checks it again; a
 * refused void shows its reason and changes nothing).
 *
 * The bill id is locked and the bill loaded through the organization scope
 * on every request, so another organization's bill (or another building's,
 * for an assigned agent) answers 404. The printable invoice and its PDF
 * come with P03-05c.
 */
#[Layout('layouts::app')]
#[Title('Bill')]
final class BillShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $billId;

    #[Locked]
    public ?string $notice = null;

    /** Why the bill is void. */
    public string $reason = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Bill $bill): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $bill);
        $this->billId = $bill->id;
    }

    public function void(BillService $bills): void
    {
        $bill = $this->bill();
        $this->authorize('void', $bill);

        $bills->void($this->user(), $bill, $this->reason);
        $this->reset('reason');
        $this->notice = __('The bill is void. It asks for nothing and accepts no payment.');
    }

    public function render(OrganizationContext $context, BillParties $parties): View
    {
        $bill = $this->bill();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $bill);
        [$row] = $parties->rows([$bill]);

        return view('billing::livewire.bill-show', [
            'organization' => $context->get(),
            'bill' => $bill,
            'row' => $row,
            'items' => BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->get(),
            'canVoid' => $this->user()->can('void', $bill),
        ]);
    }

    private function bill(): Bill
    {
        return Bill::query()->findOrFail($this->billId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
