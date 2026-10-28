<?php

declare(strict_types=1);

namespace Modules\Handovers\Livewire;

use App\Models\User;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Handovers\Enums\CreditSettlement;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverCredits;
use Modules\Handovers\Handovers\HandoverQuotes;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Handovers\Handovers\HandoverService;
use Modules\Handovers\Handovers\IncomingHandovers;
use Modules\Handovers\Models\ManagementHandover;

/**
 * A handover's page, /o/{organization}/handovers/{handover}
 * (BILLING_PAYMENTS.md § 7), for both sides: the buildings and their
 * units, who collects rent, the notice period, where it stands, and the
 * moving tenants with their unused credit and unpaid bills (P03 decision
 * 11).
 *
 * A one-way return (§ 7.1, P04-04) shows the same page with the roles
 * swapped: the landlord's owners get the accept screen (buildings, units,
 * the bill change), the agency holds the credit and chooses how it is
 * settled, the landlord confirms passed-on credit arrived. A returned
 * handover is final: no notice.
 *
 * For the receiving organization's owners, while the offer waits, it is
 * the accept screen (§ 7.3): "Your GoProperty bill goes from {before}/month
 * to {after}/month (before VAT) from your next billing day, {date}", a
 * cheaper plan when there is one, and Accept / Decline. The amount after
 * is worked out when the page renders and kept (locked) with the page;
 * accepting passes it to HandoverService, which refuses if it changed
 * since. The offering side withdraws a waiting offer; either side gives
 * notice; the side that gave it withdraws it. The landlord chooses how
 * each tenant's credit is settled and records a refund; the agency
 * confirms passed-on credit arrived (amounts locked as shown).
 *
 * Owners of the two organizations only (`handovers.manage`); any other id
 * answers 404. Every action is blocked while GoProperty staff impersonate
 * and refused in a suspended organization.
 */
#[Layout('layouts::app')]
#[Title('Handover')]
final class HandoverShow extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $handoverId;

    /** The receiving organization's monthly bill after the handover (kobo, before VAT) on the accept screen. */
    #[Locked]
    public int $shownAfterKobo = 0;

    /** @var array<int, int> line id => the tenant's credit (kobo) the page shows */
    #[Locked]
    public array $shownCredits = [];

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

    public function mount(OrganizationContext $context, IncomingHandovers $incoming, string $handover): void
    {
        $this->organizationId = $context->id();
        abort_unless(ctype_digit($handover), 404);

        $record = $this->find($incoming, (int) $handover);
        abort_if($record === null, 404);
        $this->authorize('view', $record);
        $this->handoverId = $record->id;
    }

    #[BlockedWhileImpersonating]
    public function accept(IncomingHandovers $incoming, HandoverService $handovers): void
    {
        $record = $this->record($incoming);
        $this->authorize('respond', $record);

        try {
            $handovers->accept($this->user(), $record, Money::kobo($this->shownAfterKobo));
        } catch (HandoverRefused $refused) {
            $this->addError('response', $refused->getMessage());

            return;
        }

        $this->notice = $record->isReturn()
            ? __('The buildings are yours again: they are in your buildings list.')
            : __('You now manage :organization\'s buildings: they are in your buildings list.', ['organization' => $record->organization->name]);
    }

    #[BlockedWhileImpersonating]
    public function decline(IncomingHandovers $incoming, HandoverService $handovers): void
    {
        $this->act($incoming, 'respond', fn (ManagementHandover $record) => $handovers->decline($this->user(), $record), __('You declined the offer. Nothing moved.'));
    }

    #[BlockedWhileImpersonating]
    public function withdraw(IncomingHandovers $incoming, HandoverService $handovers): void
    {
        $this->act($incoming, 'cancel', fn (ManagementHandover $record) => $handovers->cancel($this->user(), $record), __('You withdrew the offer. Nothing moved.'));
    }

    #[BlockedWhileImpersonating]
    public function giveNotice(IncomingHandovers $incoming, HandoverService $handovers): void
    {
        $this->act($incoming, 'giveNotice', fn (ManagementHandover $record) => $handovers->giveNotice($this->user(), $record), __('Notice given. Both organizations were told the end date.'));
    }

    #[BlockedWhileImpersonating]
    public function withdrawNotice(IncomingHandovers $incoming, HandoverService $handovers): void
    {
        $this->act($incoming, 'withdrawNotice', fn (ManagementHandover $record) => $handovers->withdrawNotice($this->user(), $record), __('Notice withdrawn: management goes on.'));
    }

    #[BlockedWhileImpersonating]
    public function chooseSettlement(int $lineId, string $choice, IncomingHandovers $incoming, HandoverCredits $credits): void
    {
        $settlement = CreditSettlement::tryFrom($choice);
        abort_if($settlement === null, 422);
        $this->act($incoming, 'settleCredit', fn (ManagementHandover $record) => $credits->choose($this->user(), $record, $lineId, $settlement), __('Saved: :choice.', ['choice' => __($settlement->label($this->record($incoming)->kind))]));
    }

    #[BlockedWhileImpersonating]
    public function recordRefund(int $lineId, IncomingHandovers $incoming, HandoverCredits $credits): void
    {
        $this->act($incoming, 'settleCredit', fn (ManagementHandover $record) => $credits->refund($this->user(), $record, $lineId, $this->shownCredit($lineId)), __('Refund recorded: the tenant\'s credit is closed.'));
    }

    #[BlockedWhileImpersonating]
    public function confirmArrived(int $lineId, IncomingHandovers $incoming, HandoverCredits $credits): void
    {
        $this->act($incoming, 'settleCredit', fn (ManagementHandover $record) => $credits->confirmArrived($this->user(), $record, $lineId, $this->shownCredit($lineId)), __('Recorded: the credit is now the tenant\'s opening credit here.'));
    }

    public function render(OrganizationContext $context, IncomingHandovers $incoming, HandoverQuotes $quotes, HandoverCredits $credits): View
    {
        $organization = $context->get();
        $record = $this->record($incoming);
        $user = $this->user();
        $receiving = $record->to_organization_id === $organization->id;
        $change = $receiving && $record->isAwaitingAnswer()
            ? $quotes->change($organization, array_sum($quotes->unitsIn($record->organization, $record->propertyIds())))
            : null;

        if ($change !== null) {
            $this->shownAfterKobo = $change->after->amount->kobo;
        }

        $live = $credits->liveCredits($record);
        $this->shownCredits = array_map(static fn (Money $credit): int => $credit->kobo, $live);

        return view('handovers::livewire.handover-show', [
            'organization' => $organization,
            'handover' => $record,
            'receiving' => $receiving,
            'holderSide' => $record->organization_id === $organization->id,
            'other' => $receiving ? $record->organization : $record->toOrganization,
            'change' => $change,
            'credits' => $live,
            'canRespond' => $change !== null && $user->can('respond', $record),
            'canWithdraw' => ! $receiving && $record->isAwaitingAnswer() && $user->can('cancel', $record),
            'canGiveNotice' => $record->status === HandoverStatus::Active && $user->can('giveNotice', $record),
            'canWithdrawNotice' => $record->status === HandoverStatus::Ending && $record->notice_given_by_organization_id === $organization->id && $record->ends_at?->isFuture() === true && $user->can('withdrawNotice', $record),
            'canSettle' => $user->can('settleCredit', $record),
        ]);
    }

    /** @param  callable(ManagementHandover): mixed  $step */
    private function act(IncomingHandovers $incoming, string $ability, callable $step, string $done): void
    {
        $record = $this->record($incoming);
        $this->authorize($ability, $record);
        $this->notice = null;

        try {
            $step($record);
        } catch (HandoverRefused $refused) {
            $this->addError('response', $refused->getMessage());

            return;
        }

        $this->resetValidation();
        $this->notice = $done;
    }

    private function shownCredit(int $lineId): Money
    {
        return Money::kobo($this->shownCredits[$lineId] ?? 0);
    }

    /** This organization's own handover, else one offered to it. */
    private function find(IncomingHandovers $incoming, int $id): ?ManagementHandover
    {
        $own = ManagementHandover::query()
            ->with(['organization', 'toOrganization', 'landlordOrganization', 'initiatedBy', 'respondedBy', 'buildings', 'tenants'])
            ->find($id);

        return $own ?? $incoming->find($id);
    }

    private function record(IncomingHandovers $incoming): ManagementHandover
    {
        $record = $this->find($incoming, $this->handoverId);
        abort_if($record === null, 404);

        return $record;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
