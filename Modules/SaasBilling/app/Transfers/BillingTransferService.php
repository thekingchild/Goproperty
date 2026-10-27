<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Transfers;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;
use Modules\SaasBilling\Enums\BillingTransferStatus;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Notifications\BillingTransferAnswered;
use Modules\SaasBilling\Notifications\BillingTransferOffered;
use Modules\SaasBilling\Notifications\BillingTransferWithdrawn;

/**
 * The only writer of billing transfers (AGENT.md § 3 rule 5,
 * BILLING_PAYMENTS.md § 7) and of the invitations that carry their links
 * (`invitations.kind = org_billing_transfer`):
 *
 *     initiate  the current organization offers its bill   → pending
 *     accept    the receiving organization takes it over    → completed
 *     decline   the receiving organization says no          → declined
 *     cancel    the offering organization withdraws it      → cancelled
 *     expire    nobody answered within 14 days (scheduled)  → expired
 *
 * Every step but expiry checks BillingTransferRequestPolicy for the acting
 * user in that user's own organization context (only `org-owner` holds
 * `organization.billing`; it is dropped while GoProperty staff impersonate,
 * and a suspended organization's writes are refused by the central guard).
 * Every step locks the row, refuses a transition the state machine does
 * not allow (BillingTransferRefused; nothing changes), is written to the
 * audit log (log `billing_transfers`, the transfer as subject) and sends
 * one notification set, by email and in the app.
 *
 * The row belongs to the offering organization, so the receiving side's
 * accept and decline check the user in the receiving organization and then
 * write inside the offering one (OrganizationContext::run); the receiving
 * side finds the rows through IncomingTransfers.
 *
 * Accepting records the handover only (P02 decision 5): no payer column is
 * written (the payer switch, `subscriptions.payer_organization_id` and
 * `completed_at`, is P06-06) and the receiving organization gains no
 * access to the other one's buildings.
 */
final readonly class BillingTransferService
{
    public const int VALID_DAYS = 14;

    public const string LOG = 'billing_transfers';

    public function __construct(
        private OrganizationContext $context,
        private MonthlyBills $bills,
        private OrganizationOwners $owners,
    ) {}

    /**
     * The current organization offers its GoProperty bill to $target, whose
     * owners are emailed a link to the accept screen. One open offer at a
     * time.
     *
     * @throws BillingTransferRefused
     */
    public function initiate(User $actor, Organization $target): BillingTransferRequest
    {
        Gate::forUser($actor)->authorize('create', BillingTransferRequest::class);
        $from = $this->context->get();

        if ($target->is($from)) {
            throw new BillingTransferRefused('Choose another organization: this one already pays its own bill.');
        }

        if ($target->status->blocksWrites() || $target->trashed()) {
            throw new BillingTransferRefused(TransferTargets::NOT_FOUND);
        }

        return DB::transaction(function () use ($actor, $target, $from): BillingTransferRequest {
            // One change at a time per organization: never two open offers.
            Organization::query()->whereKey($from->id)->lockForUpdate()->first();

            $open = BillingTransferRequest::query()
                ->where('status', BillingTransferStatus::Pending)
                ->where('expires_at', '>', now())
                ->with('toOrganization')
                ->first();

            if ($open !== null) {
                throw new BillingTransferRefused("Your bill is already offered to {$open->toOrganization->name}. Withdraw that offer first.");
            }

            $transfer = new BillingTransferRequest;
            $transfer->forceFill([
                'to_organization_id' => $target->id,
                'status' => BillingTransferStatus::Pending,
                'initiated_by_user_id' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();

            $token = Str::random(48);
            $invitation = new Invitation;
            $invitation->forceFill([
                'kind' => InvitationKind::OrgBillingTransfer,
                'channel' => InvitationChannel::Email,
                'roles' => [],
                'target_organization_id' => $target->id,
                'billing_transfer_id' => $transfer->id,
                'token_hash' => TransferLinks::hash($token),
                'status' => BillingTransferStatus::Pending->invitationStatus(),
                'expires_at' => $transfer->expires_at,
                'sent_at' => now(),
                'send_count' => 1,
                'invited_by_user_id' => $actor->id,
            ])->save();

            $bill = $this->bills->for($from);

            $this->audit('billing_transfer.offered', $transfer, $actor, "Offered the GoProperty bill to {$target->name}", [
                'invitation_id' => $invitation->id,
                'expires_at' => $transfer->expires_at?->toIso8601String(),
                'monthly_amount_kobo' => $bill->amount->kobo,
                'billable_units' => $bill->units,
                'plan_key' => $bill->plan->key,
            ]);

            Notification::send($this->owners->withEmail($target), new BillingTransferOffered(
                token: $token,
                transferId: $transfer->id,
                fromOrganization: $from->name,
                toOrganization: $target->name,
                toSlug: $target->slug,
                offeredBy: $actor->name,
                monthlyAmount: $bill->amount->format(),
                expiresAt: $transfer->expires_at ?? now()->toImmutable(),
            ));

            return $transfer;
        });
    }

    /**
     * The receiving organization (the current one) takes the bill over.
     * $shown is the monthly amount its owner was shown on the accept screen
     * (BILLING_PAYMENTS.md § 7 rule 2): when the bill has changed since
     * (units added or archived), nothing is accepted and the owner sees the
     * new amount.
     *
     * @throws BillingTransferRefused
     */
    public function accept(User $actor, BillingTransferRequest $transfer, Money $shown): BillingTransferRequest
    {
        Gate::forUser($actor)->authorize('respond', $transfer);
        $receiver = $this->context->get();
        $from = $this->offeringOrganization($transfer);

        return $this->context->run($from, fn (): BillingTransferRequest => DB::transaction(function () use ($actor, $transfer, $shown, $receiver, $from): BillingTransferRequest {
            $locked = $this->lockAddressedTo($transfer, $receiver);
            $this->ensureCanMove($locked, BillingTransferStatus::Completed);

            $bill = $this->bills->for($from);

            if (! $bill->amount->equals($shown)) {
                throw new BillingTransferRefused("The bill has changed since this page was opened: it is now {$bill->amount->format()} a month. Check the new amount and accept again.");
            }

            $locked->forceFill([
                'status' => BillingTransferStatus::Completed,
                'responded_by_user_id' => $actor->id,
                'responded_at' => now(),
                'plan_key' => $bill->plan->key,
                'billable_units' => $bill->units,
                'monthly_amount_kobo' => $bill->amount,
            ])->save();

            $this->closeInvitation($locked, $actor);
            $this->audit('billing_transfer.accepted', $locked, $actor, "{$receiver->name} accepted to take over the GoProperty bill", [
                'monthly_amount_kobo' => $bill->amount->kobo,
                'billable_units' => $bill->units,
                'plan_key' => $bill->plan->key,
                'vat_percent' => $bill->vatPercent,
            ]);

            Notification::send($this->initiators($locked, $from), new BillingTransferAnswered(
                outcome: BillingTransferStatus::Completed,
                transferId: $locked->id,
                fromOrganization: $from->name,
                fromSlug: $from->slug,
                toOrganization: $receiver->name,
                monthlyAmount: $bill->amount->format(),
            ));

            return $locked;
        }));
    }

    /**
     * The receiving organization (the current one) declines: nothing changes.
     *
     * @throws BillingTransferRefused
     */
    public function decline(User $actor, BillingTransferRequest $transfer): BillingTransferRequest
    {
        Gate::forUser($actor)->authorize('respond', $transfer);
        $receiver = $this->context->get();
        $from = $this->offeringOrganization($transfer);

        return $this->context->run($from, fn (): BillingTransferRequest => DB::transaction(function () use ($actor, $transfer, $receiver, $from): BillingTransferRequest {
            $locked = $this->lockAddressedTo($transfer, $receiver);
            $this->ensureCanMove($locked, BillingTransferStatus::Declined);

            $locked->forceFill([
                'status' => BillingTransferStatus::Declined,
                'responded_by_user_id' => $actor->id,
                'responded_at' => now(),
            ])->save();

            $this->closeInvitation($locked, $actor);
            $this->audit('billing_transfer.declined', $locked, $actor, "{$receiver->name} declined to take over the GoProperty bill");

            Notification::send($this->initiators($locked, $from), new BillingTransferAnswered(
                outcome: BillingTransferStatus::Declined,
                transferId: $locked->id,
                fromOrganization: $from->name,
                fromSlug: $from->slug,
                toOrganization: $receiver->name,
            ));

            return $locked;
        }));
    }

    /**
     * The offering organization (the current one) withdraws its offer
     * before an answer; the link stops working.
     *
     * @throws BillingTransferRefused
     */
    public function cancel(User $actor, BillingTransferRequest $transfer): BillingTransferRequest
    {
        Gate::forUser($actor)->authorize('cancel', $transfer);
        $from = $this->context->get();

        return DB::transaction(function () use ($actor, $transfer, $from): BillingTransferRequest {
            $locked = BillingTransferRequest::query()->with('toOrganization')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->ensureCanMove($locked, BillingTransferStatus::Cancelled);

            $locked->forceFill([
                'status' => BillingTransferStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $this->closeInvitation($locked, null);
            $this->audit('billing_transfer.cancelled', $locked, $actor, "Withdrew the offer of the GoProperty bill to {$locked->toOrganization->name}");

            Notification::send($this->owners->withEmail($locked->toOrganization), new BillingTransferWithdrawn(
                transferId: $locked->id,
                fromOrganization: $from->name,
                toOrganization: $locked->toOrganization->name,
                toSlug: $locked->toOrganization->slug,
            ));

            return $locked;
        });
    }

    /**
     * Records that a pending offer ran out unanswered; for the scheduled
     * job and the opening of a link past its date. Runs as the system, in
     * the offering organization's context.
     *
     * @throws BillingTransferRefused when it is not pending, or not yet due
     */
    public function expire(BillingTransferRequest $transfer): BillingTransferRequest
    {
        if (! $this->context->has() || $this->context->id() !== $transfer->organization_id) {
            throw new LogicException('Expire a billing transfer inside the offering organization\'s context.');
        }

        $from = $this->context->get();

        return DB::transaction(function () use ($transfer, $from): BillingTransferRequest {
            $locked = BillingTransferRequest::query()->with('toOrganization')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canMoveTo(BillingTransferStatus::Expired)) {
                throw new BillingTransferRefused($this->closedMessage($locked));
            }

            if (! $locked->isPastExpiry()) {
                throw new BillingTransferRefused('This offer is still open until '.$this->date($locked).'.');
            }

            $locked->forceFill(['status' => BillingTransferStatus::Expired])->save();

            $this->closeInvitation($locked, null);
            $this->audit('billing_transfer.expired', $locked, null, "The offer of the GoProperty bill to {$locked->toOrganization->name} expired unanswered");

            Notification::send($this->initiators($locked, $from), new BillingTransferAnswered(
                outcome: BillingTransferStatus::Expired,
                transferId: $locked->id,
                fromOrganization: $from->name,
                fromSlug: $from->slug,
                toOrganization: $locked->toOrganization->name,
            ));

            return $locked;
        });
    }

    /**
     * Expires every pending offer of the current organization past its 14
     * days; safe to run again (an expired offer is no longer pending).
     *
     * @return int how many expired
     */
    public function expireDue(): int
    {
        $due = BillingTransferRequest::query()
            ->where('status', BillingTransferStatus::Pending)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($due as $transfer) {
            try {
                $this->expire($transfer);
                $expired++;
            } catch (BillingTransferRefused) {
                // Answered or withdrawn meanwhile: nothing to do.
            }
        }

        return $expired;
    }

    /** The offering organization of a transfer (the row's owner). */
    private function offeringOrganization(BillingTransferRequest $transfer): Organization
    {
        return Organization::query()->findOrFail($transfer->organization_id);
    }

    /**
     * The row, locked, inside the offering organization's context, if it is
     * addressed to the receiving organization (the policy checked this too).
     *
     * @throws BillingTransferRefused
     */
    private function lockAddressedTo(BillingTransferRequest $transfer, Organization $receiver): BillingTransferRequest
    {
        $locked = BillingTransferRequest::query()->whereKey($transfer->id)->lockForUpdate()->first();

        if ($locked === null || $locked->to_organization_id !== $receiver->id) {
            throw new BillingTransferRefused('This offer was not made to your organization.');
        }

        return $locked;
    }

    /** @throws BillingTransferRefused */
    private function ensureCanMove(BillingTransferRequest $transfer, BillingTransferStatus $next): void
    {
        if (! $transfer->status->canMoveTo($next)) {
            throw new BillingTransferRefused($this->closedMessage($transfer));
        }

        if ($transfer->isPastExpiry()) {
            throw new BillingTransferRefused('This offer expired on '.$this->date($transfer).'.');
        }
    }

    private function closedMessage(BillingTransferRequest $transfer): string
    {
        return match ($transfer->status) {
            BillingTransferStatus::Completed => 'This offer was already accepted.',
            BillingTransferStatus::Declined => 'This offer was declined.',
            BillingTransferStatus::Cancelled => 'This offer was withdrawn.',
            BillingTransferStatus::Expired => 'This offer expired on '.$this->date($transfer).'.',
            BillingTransferStatus::Pending => 'This offer is still waiting for an answer.',
        };
    }

    private function date(BillingTransferRequest $transfer): string
    {
        return $transfer->expires_at?->timezone('Africa/Lagos')->format('j F Y') ?? 'its date';
    }

    /** The invitation that carries the link follows the transfer; its link no longer works. */
    private function closeInvitation(BillingTransferRequest $transfer, ?User $acceptedBy): void
    {
        $invitation = Invitation::query()
            ->where('kind', InvitationKind::OrgBillingTransfer)
            ->where('billing_transfer_id', $transfer->id)
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            return;
        }

        $status = $transfer->status->invitationStatus();
        $invitation->forceFill(array_filter([
            'status' => $status,
            'accepted_at' => $transfer->status === BillingTransferStatus::Completed ? now() : null,
            'accepted_by_user_id' => $transfer->status === BillingTransferStatus::Completed ? $acceptedBy?->id : null,
            'cancelled_at' => $transfer->status === BillingTransferStatus::Cancelled ? now() : null,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    /**
     * Who hears about the answer: the owner who made the offer, while still
     * an owner of the offering organization; otherwise its owners.
     *
     * @return Collection<int, User>
     */
    private function initiators(BillingTransferRequest $transfer, Organization $from): Collection
    {
        $owners = $this->owners->withEmail($from);
        $initiator = $owners->firstWhere('id', $transfer->initiated_by_user_id);

        return $initiator instanceof User ? new Collection([$initiator]) : $owners;
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, BillingTransferRequest $transfer, ?User $actor, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($transfer)
            ->withProperties([
                'transfer_id' => $transfer->id,
                'from_organization_id' => $transfer->organization_id,
                'to_organization_id' => $transfer->to_organization_id,
                'status' => $transfer->status->value,
                ...$properties,
            ]);

        if ($actor !== null) {
            $entry->causedBy($actor);
        }

        $entry->log($description);
    }
}
