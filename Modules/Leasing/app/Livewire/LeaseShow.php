<?php

declare(strict_types=1);

namespace Modules\Leasing\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Leasing\Contracts\LeasePagePanels;
use Modules\Leasing\Contracts\RentPaymentLimits;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Lists\LeaseParties;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;

/**
 * A lease, /o/{organization}/leases/{lease}: its tenant, unit, dates,
 * rent, contract number, the deposit ledger and the lease it renews or
 * that renews it, with the actions its state allows (LeaseStatus):
 * activate or cancel a draft, renew or end an active lease, settle the
 * deposit of an ended one; and, once activated, a link to the contract PDF
 * (Http\Controllers\LeaseContractController, P03-03b); below it, the
 * panels other modules add (Contracts\LeasePagePanels: the lease's
 * maintenance tickets, P04-03). Each action checks LeasePolicy and goes through
 * Leases\LeaseService, which checks it again; a refused move shows its
 * reason and changes nothing.
 *
 * The lease id is locked and the lease loaded through the organization
 * scope on every request, so another organization's lease (or another
 * building's, for an assigned agent) answers 404.
 */
#[Layout('layouts::app')]
#[Title('Lease')]
final class LeaseShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $leaseId;

    #[Locked]
    public ?string $notice = null;

    /** Why the lease ends early, or why a draft is cancelled. */
    public string $reason = '';

    /** Deposit refunded and kept, in naira as typed, and why it is kept. */
    public string $refunded = '';

    public string $kept = '';

    public string $note = '';

    /** Payments allowed on each rent bill of this lease (P03-17): "" = the organization's default, else "1" … "12". */
    public string $rentPayments = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, Lease $lease): void
    {
        $this->organizationId = $context->id();
        $this->authorize('view', $lease);
        $this->leaseId = $lease->id;
        $this->rentPayments = (string) ($lease->max_rent_payments ?? '');
    }

    /** Change how many payments a rent bill of this lease may be paid in (P03-17; owners and administrators). */
    public function setRentPayments(LeaseService $leases): void
    {
        $lease = $this->lease();
        $this->authorize('setPaymentLimit', $lease);
        $this->validate(['rentPayments' => ['nullable', 'integer', 'between:1,'.RentPaymentLimits::MAX]], [], ['rentPayments' => __('number of payments')]);

        $lease = $leases->setRentPaymentLimit($this->user(), $lease, $this->rentPayments === '' ? null : (int) $this->rentPayments);
        $this->notice = __('Saved: rent bills of this lease follow the new number of payments from the next payment.');
    }

    public function activate(LeaseService $leases): void
    {
        $lease = $this->lease();
        $this->authorize('activate', $lease);

        $lease = $leases->activate($this->user(), $lease);
        $this->notice = __('The lease is active: :contract. The unit is now occupied.', ['contract' => $lease->contract_number]);
    }

    public function terminate(LeaseService $leases): void
    {
        $lease = $this->lease();
        $this->authorize('terminate', $lease);
        $wasDraft = $lease->status === LeaseStatus::Draft;

        $leases->terminate($this->user(), $lease, $this->reason);
        $this->reset('reason');
        $this->notice = $wasDraft ? __('The draft was cancelled and the unit released.') : __('The lease was ended. The unit is released unless another lease holds it.');
    }

    public function settleDeposit(LeaseService $leases): void
    {
        $lease = $this->lease();
        $this->authorize('settleDeposit', $lease);

        $leases->settleDeposit($this->user(), $lease, ['refunded' => $this->refunded, 'kept' => $this->kept, 'note' => $this->note]);
        $this->reset('refunded', 'kept', 'note');
        $this->notice = __('The deposit was recorded.');
    }

    public function render(OrganizationContext $context, LeaseParties $parties, LeaseService $leases, LeasePagePanels $panels, RentPaymentLimits $limits): View
    {
        $lease = $this->lease();
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $lease);
        $user = $this->user();
        [$row] = $parties->rows([$lease]);

        return view('leasing::livewire.lease-show', [
            'organization' => $context->get(),
            'lease' => $lease,
            'row' => $row,
            'renewalOf' => $lease->renewalOf,
            'renewals' => $lease->renewals()->orderBy('id')->get(),
            'entries' => LeaseDepositEntry::query()->where('lease_id', $lease->id)->orderBy('id')->get(),
            'depositBalance' => $leases->depositBalance($lease),
            'canEdit' => $user->can('update', $lease),
            'canActivate' => $user->can('activate', $lease),
            'canRenew' => $user->can('renew', $lease) && ! $lease->renewals()->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])->exists(),
            'canTerminate' => $user->can('terminate', $lease),
            'canSettle' => $user->can('settleDeposit', $lease) && $leases->depositBalance($lease)->isPositive(),
            // Rent part payments (P03-17): this lease's number, else the organization's.
            'rentPaymentLimit' => $limits->forLease($lease->id),
            'rentPaymentDefault' => $limits->organizationDefault(),
            'canSetRentPayments' => $user->can('setPaymentLimit', $lease),
            'maxRentPayments' => RentPaymentLimits::MAX,
            // The contract PDF (P03-03b), once the lease has been activated.
            'canDownloadContract' => $user->can('downloadContract', $lease),
            'flash' => session('lease-status'),
            // Other modules' panels below the lease (P04-03: its tickets and "Report a repair").
            'panels' => $panels->all(),
        ]);
    }

    private function lease(): Lease
    {
        return Lease::query()->findOrFail($this->leaseId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
