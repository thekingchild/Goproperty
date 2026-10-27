<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\SaasBilling\Lists\BillingTransfersMadeList;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Transfers\BillingTransferRefused;
use Modules\SaasBilling\Transfers\BillingTransferService;
use Modules\SaasBilling\Transfers\IncomingTransfers;
use Modules\SaasBilling\Transfers\MonthlyBills;
use Modules\SaasBilling\Transfers\TransferTargets;

/**
 * Billing transfers, /o/{organization}/billing-transfers
 * (BILLING_PAYMENTS.md § 7): this organization's GoProperty bill as it
 * stands, the form to offer it to another organization already on
 * GoProperty (by its web address or an owner's email), the offers made
 * (with "Withdraw" while open), and the invite inbox: offers other
 * organizations made to this one, each opening the accept screen
 * (BillingTransferReview). Owners only (`organization.billing`).
 *
 * Runs inside the `organization` middleware, replayed for Livewire
 * updates; the component refuses any other organization. Every action
 * checks BillingTransferRequestPolicy (the service checks again), is
 * blocked while GoProperty staff impersonate, and is refused in a
 * suspended organization by the central guard. Offers are limited per
 * organization (the form must not become a way to probe for accounts).
 */
#[Layout('layouts::app')]
#[Title('Billing transfers')]
final class BillingTransfers extends Component
{
    use WithListExports;

    /** Offer attempts (found or not) per organization per hour. */
    public const int ATTEMPTS_PER_HOUR = 10;

    public const string NOTICE = 'billing-transfers.notice';

    #[Locked]
    public int $organizationId;

    public string $target = '';

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', BillingTransferRequest::class);
        $notice = session(self::NOTICE);
        $this->notice = is_string($notice) ? $notice : null;
    }

    #[BlockedWhileImpersonating]
    public function offer(BillingTransferService $transfers, TransferTargets $targets): void
    {
        $this->validate(['target' => ['required', 'string', 'max:255']], attributes: ['target' => 'web address or email']);
        $this->authorize('create', BillingTransferRequest::class);
        $this->notice = null;

        $key = 'billing-transfers:offers:'.$this->organizationId;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_HOUR)) {
            $this->addError('target', 'Too many offers were tried. Try again in an hour.');

            return;
        }

        RateLimiter::hit($key, 3600);
        $organization = $targets->find($this->target);

        if ($organization === null) {
            $this->addError('target', TransferTargets::NOT_FOUND);

            return;
        }

        try {
            $transfers->initiate($this->user(), $organization);
        } catch (BillingTransferRefused $refused) {
            $this->addError('target', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->reset('target');
        $this->notice = "Your bill was offered to {$organization->name}. Their owners have ".BillingTransferService::VALID_DAYS.' days to accept or decline; you will be told either way.';
    }

    #[BlockedWhileImpersonating]
    public function withdraw(int $transferId, BillingTransferService $transfers): void
    {
        $transfer = BillingTransferRequest::query()->with('toOrganization')->findOrFail($transferId);
        $this->authorize('cancel', $transfer);
        $this->notice = null;

        try {
            $transfers->cancel($this->user(), $transfer);
        } catch (BillingTransferRefused $refused) {
            $this->addError('outgoing', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->notice = "Your offer to {$transfer->toOrganization->name} was withdrawn.";
    }

    public function render(OrganizationContext $context, MonthlyBills $bills, IncomingTransfers $incoming, BillingTransfersMadeList $made): View
    {
        $organization = $context->get();

        return view('saasbilling::livewire.billing-transfers', [
            'organization' => $organization,
            'bill' => $bills->for($organization),
            'outgoing' => $made->query($this->user(), [])->limit(BillingTransfersMadeList::PAGE_LIMIT)->get(),
            'incoming' => $incoming->recent(),
            'validDays' => BillingTransferService::VALID_DAYS,
        ]);
    }

    /** Both lists export (P02-07, Lists\BillingTransfersMadeList and …ReceivedList); no filters. */
    protected function listExports(): array
    {
        return ['billing_transfers_made' => [], 'billing_transfers_received' => []];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
