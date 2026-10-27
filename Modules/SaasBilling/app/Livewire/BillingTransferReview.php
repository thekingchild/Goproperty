<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Livewire;

use App\Models\User;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Transfers\BillingTransferRefused;
use Modules\SaasBilling\Transfers\BillingTransferService;
use Modules\SaasBilling\Transfers\IncomingTransfers;
use Modules\SaasBilling\Transfers\MonthlyBills;

/**
 * The accept screen, /o/{organization}/billing-transfers/{transfer}
 * (BILLING_PAYMENTS.md § 7 rule 2): an offer another organization made to
 * this one, stating "You are taking over the GoProperty subscription bill
 * for {amount}/month from {date}" with the plan and units behind the
 * amount, and Accept / Decline. Owners of the receiving organization only
 * (`organization.billing`); the offer is found only among those made to
 * this organization (IncomingTransfers), so any other id answers 404.
 *
 * The amount is worked out when the page renders and kept (locked) with
 * the page; accepting passes it to BillingTransferService, which refuses
 * if the bill has changed since, so nobody accepts an amount they did not
 * see. Accept and Decline are blocked while GoProperty staff impersonate
 * and refused in a suspended organization.
 */
#[Layout('layouts::app')]
#[Title('Billing transfer offer')]
final class BillingTransferReview extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $transferId;

    /** The monthly amount (kobo, before VAT) on the screen the owner sees. */
    #[Locked]
    public int $shownAmountKobo = 0;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, IncomingTransfers $incoming, string $transfer): void
    {
        $this->organizationId = $context->id();
        abort_unless(ctype_digit($transfer), 404);

        $record = $incoming->find((int) $transfer);
        abort_if($record === null, 404);
        $this->authorize('view', $record);
        $this->transferId = $record->id;
    }

    #[BlockedWhileImpersonating]
    public function accept(IncomingTransfers $incoming, BillingTransferService $transfers): void
    {
        $record = $this->record($incoming);
        $this->authorize('respond', $record);

        try {
            $transfers->accept($this->user(), $record, Money::kobo($this->shownAmountKobo));
        } catch (BillingTransferRefused $refused) {
            $this->addError('response', $refused->getMessage());

            return;
        }

        $this->done("You took over {$record->organization->name}'s GoProperty bill.");
    }

    #[BlockedWhileImpersonating]
    public function decline(IncomingTransfers $incoming, BillingTransferService $transfers): void
    {
        $record = $this->record($incoming);
        $this->authorize('respond', $record);

        try {
            $transfers->decline($this->user(), $record);
        } catch (BillingTransferRefused $refused) {
            $this->addError('response', $refused->getMessage());

            return;
        }

        $this->done("You declined {$record->organization->name}'s offer. Nothing changes.");
    }

    public function render(OrganizationContext $context, IncomingTransfers $incoming, MonthlyBills $bills): View
    {
        $record = $this->record($incoming);
        $bill = $record->isOpen() ? $bills->for($record->organization) : null;

        if ($bill !== null) {
            $this->shownAmountKobo = $bill->amount->kobo;
        }

        return view('saasbilling::livewire.billing-transfer-review', [
            'organization' => $context->get(),
            'transfer' => $record,
            'bill' => $bill,
            'canRespond' => $record->isOpen() && auth()->user()?->can('respond', $record) === true,
        ]);
    }

    private function record(IncomingTransfers $incoming): BillingTransferRequest
    {
        $record = $incoming->find($this->transferId);
        abort_if($record === null, 404);

        return $record;
    }

    private function done(string $notice): void
    {
        session()->flash(BillingTransfers::NOTICE, $notice);
        $this->redirectRoute('workspace.billing-transfers.index', ['organization' => app(OrganizationContext::class)->get()->slug]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
