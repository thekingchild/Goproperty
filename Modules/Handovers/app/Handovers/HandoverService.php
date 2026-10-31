<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\OrganizationType;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\BuildingMoveFailed;
use App\Tenancy\Moves\MoveDirection;
use App\Tenancy\Moves\MovedTenantsChanged;
use App\Tenancy\Moves\OrganizationMoves;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Modules\Handovers\Enums\HandoverKind;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Enums\RentCollection;
use Modules\Handovers\Models\HandoverBuilding;
use Modules\Handovers\Models\HandoverTenant;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverOffered;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Throwable;

/**
 * The only writer of management handovers (AGENT.md § 3 rule 5,
 * BILLING_PAYMENTS.md § 7) and of the invitations that carry their links
 * (`invitations.kind = org_billing_transfer`, `management_handover_id`):
 *
 *     offer           the landlord offers chosen buildings to an agency  → pending
 *     accept          the agency accepts: the buildings move              → active
 *     decline         the agency says no: nothing moves                   → declined
 *     cancel          the landlord withdraws the offer                    → cancelled
 *     expire          nobody answered within 14 days (hourly job)         → expired
 *     giveNotice      either side ends it with notice                     → ending
 *     withdrawNotice  the side that gave notice, before the end date      → active
 *     end             the end date passed (hourly job): the buildings go back → ended
 *     offerReturn     an agency offers buildings back to their landlord     → pending (kind return)
 *     accept (return) the landlord accepts: the buildings move for good      → returned
 *
 * Every step but the system's (expire, end) checks
 * ManagementHandoverPolicy for the acting user in that user's own
 * organization context (only `org-owner` holds `handovers.manage`; it is
 * dropped while GoProperty staff impersonate, and a suspended
 * organization holds none of it). Every step locks the row inside the
 * offering organization's context, refuses a move the state machine does
 * not allow (HandoverRefused; nothing changes), is written to the audit
 * log (log `handovers`, the handover as subject, both organizations'
 * ids) and tells both sides once, by email and in the app.
 *
 * Accepting and ending move the buildings' live records through
 * App\Tenancy\Moves\OrganizationMoves inside the same transaction as the
 * state change: each module moves its own records, any failure rolls back
 * everything (BILLING_PAYMENTS.md § 7.7 rule 2). History (bills, receipts,
 * payments, documents, audit entries) never moves.
 *
 * Built: a landlord's organization (type `property_owner` or `hybrid`)
 * offers its buildings to an agency (`agency` or `hybrid`); and (§ 7.1,
 * P04-04) an agency returns buildings it holds without a handover to the
 * landlord's own organization (`offerReturn`, kind `return`): the same
 * offer, answer, withdrawal and expiry, but accepting ends it as
 * `returned`, final (no notice, no end), and the buildings move for good.
 * A return goes only to an organization one of whose owners is the account
 * linked to the buildings' owner record in the agency (so an agency cannot
 * push buildings into an unrelated organization).
 */
final readonly class HandoverService
{
    public const int VALID_DAYS = 14;

    public const int DEFAULT_NOTICE_DAYS = 30;

    public const int MAX_NOTICE_DAYS = 180;

    public const string LOG = 'handovers';

    public function __construct(
        private OrganizationContext $context,
        private PropertyDirectory $properties,
        private OfferableBuildings $offerable,
        private HandoverQuotes $quotes,
        private HandoverCredits $credits,
        private HandoverNotices $notices,
        private OrganizationMoves $moves,
        private TenantOrigins $origins,
    ) {}

    /**
     * The current organization (the landlord's) offers these buildings to
     * $target for management. Its owners are emailed a link to the accept
     * screen.
     *
     * @param  list<int>  $propertyIds
     *
     * @throws HandoverRefused
     */
    public function offer(User $actor, Organization $target, array $propertyIds, int $noticeDays = self::DEFAULT_NOTICE_DAYS, RentCollection $rent = RentCollection::Agency): ManagementHandover
    {
        Gate::forUser($actor)->authorize('create', ManagementHandover::class);
        $from = $this->context->get();
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));

        if (! in_array($from->type, [OrganizationType::PropertyOwner, OrganizationType::Hybrid], true)) {
            throw new HandoverRefused(__('Only a landlord\'s organization hands its buildings to an agency.'));
        }

        if ($target->is($from)) {
            throw new HandoverRefused(__('Choose another organization: this one already manages these buildings.'));
        }

        if (! HandoverTargets::takesHandovers($target)) {
            throw new HandoverRefused(__(HandoverTargets::NOT_FOUND));
        }

        if ($propertyIds === []) {
            throw new HandoverRefused(__('Choose at least one building to hand over.'));
        }

        if ($noticeDays < 0 || $noticeDays > self::MAX_NOTICE_DAYS) {
            throw new HandoverRefused(__('The notice period is between 0 and :max days.', ['max' => self::MAX_NOTICE_DAYS]));
        }

        if (! $rent->isOffered()) {
            throw new HandoverRefused(__('Only "the agency collects rent" can be chosen for now: the landlord collecting needs a collection account per building, which GoProperty does not have yet.'));
        }

        return DB::transaction(function () use ($actor, $target, $from, $propertyIds, $noticeDays, $rent): ManagementHandover {
            $buildings = $this->lockOfferable($from, $propertyIds);
            $units = $this->quotes->unitsIn($from, $propertyIds);

            $handover = new ManagementHandover;
            $handover->forceFill([
                'to_organization_id' => $target->id,
                'landlord_organization_id' => $from->id,
                'kind' => HandoverKind::Management,
                'status' => HandoverStatus::Pending,
                'rent_collected_by' => $rent,
                'notice_days' => $noticeDays,
                'initiated_by_user_id' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();

            [$invitation, $token] = $this->recordOffer($handover, $actor, $target, $from, $propertyIds, $buildings, $units);
            $unitCount = array_sum($units);
            $what = $this->describe(count($propertyIds), $unitCount);

            $this->audit('handover.offered', $handover, $actor, "Offered {$what} to {$target->name} for management", [
                'invitation_id' => $invitation->id,
                'property_ids' => $propertyIds,
                'units' => $unitCount,
                'notice_days' => $noticeDays,
                'rent_collected_by' => $rent->value,
                'expires_at' => $handover->expires_at?->toIso8601String(),
            ]);

            $this->notices->offered($handover, new HandoverOffered(
                token: $token,
                handoverId: $handover->id,
                fromOrganization: $from->name,
                toOrganization: $target->name,
                toSlug: $target->slug,
                offeredBy: $actor->name,
                buildings: count($propertyIds),
                units: $unitCount,
                expiresAt: $handover->expires_at ?? now()->toImmutable(),
            ), __('You offered :what to :agency', ['what' => $what, 'agency' => $target->name]), [
                __(':name offered :what to :agency for management. Nothing moves unless one of its owners accepts by :date.', ['name' => $actor->name, 'what' => $what, 'agency' => $target->name, 'date' => $this->date($handover->expires_at)]),
            ]);

            return $handover;
        });
    }

    /**
     * The current organization (an agency) offers buildings it holds
     * without a handover back to their landlord's own organization $target,
     * for good (BILLING_PAYMENTS.md § 7.1, P04-04). Its owners are emailed
     * a link to the accept screen. No rent collection and no notice: once
     * accepted the buildings are the landlord's own again.
     *
     * Safeguard: every building's owner record here must be linked to an
     * account (the owner portal, P03-15), the same account for all of
     * them, and that account must be an owner of $target.
     *
     * @param  list<int>  $propertyIds
     *
     * @throws HandoverRefused
     */
    public function offerReturn(User $actor, Organization $target, array $propertyIds): ManagementHandover
    {
        Gate::forUser($actor)->authorize('offerReturn', ManagementHandover::class);
        $from = $this->context->get();
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));

        if (! in_array($from->type, [OrganizationType::Agency, OrganizationType::Hybrid], true)) {
            throw new HandoverRefused(__('Only an agency returns buildings to their landlord.'));
        }

        if ($target->is($from)) {
            throw new HandoverRefused(__('Choose the landlord\'s own organization: these buildings are already here.'));
        }

        if (! HandoverTargets::takesReturns($target)) {
            throw new HandoverRefused(__(HandoverTargets::LANDLORD_NOT_FOUND));
        }

        if ($propertyIds === []) {
            throw new HandoverRefused(__('Choose at least one building to return.'));
        }

        return DB::transaction(function () use ($actor, $target, $from, $propertyIds): ManagementHandover {
            $buildings = $this->lockOfferable($from, $propertyIds);
            $landlord = $this->returnOwner($buildings, $target);
            $units = $this->quotes->unitsIn($from, $propertyIds);

            $handover = new ManagementHandover;
            $handover->forceFill([
                'to_organization_id' => $target->id,
                'landlord_organization_id' => $target->id,
                'kind' => HandoverKind::Return,
                'status' => HandoverStatus::Pending,
                'rent_collected_by' => null,
                'notice_days' => 0,
                'initiated_by_user_id' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();

            [$invitation, $token] = $this->recordOffer($handover, $actor, $target, $from, $propertyIds, $buildings, $units);
            $unitCount = array_sum($units);
            $what = $this->describe(count($propertyIds), $unitCount);

            $this->audit('handover.return_offered', $handover, $actor, "Offered to return {$what} to {$target->name}", [
                'invitation_id' => $invitation->id,
                'property_ids' => $propertyIds,
                'units' => $unitCount,
                'owner_user_id' => $landlord->id,
                'expires_at' => $handover->expires_at?->toIso8601String(),
            ]);

            $this->notices->offered($handover, new HandoverOffered(
                token: $token,
                handoverId: $handover->id,
                fromOrganization: $from->name,
                toOrganization: $target->name,
                toSlug: $target->slug,
                offeredBy: $actor->name,
                buildings: count($propertyIds),
                units: $unitCount,
                expiresAt: $handover->expires_at ?? now()->toImmutable(),
                isReturn: true,
            ), __('You offered to return :what to :landlord', ['what' => $what, 'landlord' => $target->name]), [
                __(':name offered to return :what to :landlord, for good. Nothing moves unless one of its owners accepts by :date.', ['name' => $actor->name, 'what' => $what, 'landlord' => $target->name, 'date' => $this->date($handover->expires_at)]),
            ]);

            return $handover;
        });
    }

    /**
     * The receiving organization (the current one) accepts: the buildings
     * and their live records move to it, in one transaction. $shownAfter is
     * the monthly bill after the handed-over units its owner was shown on
     * the accept screen (BILLING_PAYMENTS.md § 7.3): when it has changed
     * since, nothing is accepted.
     *
     * A management becomes `active`; a one-way return (§ 7.1) becomes
     * `returned`, final: the landlord's owner record here takes the
     * buildings, and the agency's link to the landlord is removed there.
     *
     * @throws HandoverRefused
     */
    public function accept(User $actor, ManagementHandover $handover, Money $shownAfter): ManagementHandover
    {
        Gate::forUser($actor)->authorize('respond', $handover);
        $receiver = $this->context->get();
        $from = $this->offeringOrganization($handover);
        $attempt = new MoveAttempt;

        try {
            return DB::transaction(fn (): ManagementHandover => $this->context->run($from, function () use ($actor, $handover, $shownAfter, $receiver, $from, $attempt): ManagementHandover {
                $locked = $this->lockAddressedTo($handover, $receiver);
                $accepted = $locked->kind->acceptedStatus();
                $this->ensureCanMove($locked, $accepted);
                $propertyIds = $locked->propertyIds();

                foreach ($propertyIds as $propertyId) {
                    if (! $this->properties->isActive($propertyId)) {
                        throw new HandoverRefused(__(':organization no longer has one of these buildings as active. Ask them to offer again.', ['organization' => $from->name]));
                    }
                }

                // A return: the buildings' owner must still be an owner here (their links may have changed since the offer).
                $landlord = $locked->isReturn()
                    ? $this->returnOwner($this->properties->findMany($propertyIds), $receiver)
                    : $locked->initiatedBy;

                $units = $this->quotes->unitsIn($from, $propertyIds);
                $change = $this->quotes->change($receiver, array_sum($units));

                if (! $change->after->amount->equals($shownAfter)) {
                    throw new HandoverRefused(__('The bill has changed since this page was opened: it would now be :amount a month. Check the new amount and accept again.', ['amount' => $change->after->amount->format()]));
                }

                $this->credits->refresh($locked, $propertyIds);

                $move = $attempt->move = $this->moves->run(
                    $from,
                    $receiver,
                    $locked->isReturn() ? MoveDirection::OneWayReturn : MoveDirection::Handover,
                    $propertyIds,
                    $locked->id,
                    landlordOrganization: $locked->isReturn() ? $receiver : $from,
                    landlord: $landlord,
                    knownTenants: $this->origins->between($from, $receiver),
                );

                foreach ($locked->buildings as $building) {
                    $building->forceFill([
                        'unit_count' => $units[$building->property_id] ?? 0,
                        'previous_owner_profile_id' => $locked->isReturn() ? null : ($move->previousOwnerProfileIds()[$building->property_id] ?? null),
                    ])->save();
                }

                foreach (HandoverTenant::query()->where('management_handover_id', $locked->id)->get() as $line) {
                    $line->forceFill(['receiving_tenant_profile_id' => $move->tenantIn($line->tenant_profile_id)])->save();
                }

                $locked->forceFill([
                    'status' => $accepted,
                    'responded_by_user_id' => $actor->id,
                    'responded_at' => now(),
                    'accepted_at' => now(),
                    'plan_key' => $change->after->plan->key,
                    'billable_units' => $change->units,
                    'monthly_before_kobo' => $change->before->amount,
                    'monthly_after_kobo' => $change->after->amount,
                    'owner_profile_id' => $move->ownerProfileId(),
                ])->save();

                $this->closeInvitation($locked, $actor);
                $what = $this->describe(count($propertyIds), $change->units);
                $this->audit($locked->isReturn() ? 'handover.returned' : 'handover.accepted', $locked, $actor, $locked->isReturn() ? "{$receiver->name} accepted the return of {$what} from {$from->name}" : "{$receiver->name} accepted to manage {$what}", [
                    'property_ids' => $propertyIds,
                    'billable_units' => $change->units,
                    'monthly_before_kobo' => $change->before->amount->kobo,
                    'monthly_after_kobo' => $change->after->amount->kobo,
                    'plan_key' => $change->after->plan->key,
                    'vat_percent' => $change->after->vatPercent,
                    // The invoice the receiving organization's next billing day brings (P06-06).
                    'next_billing_day' => $change->from->toDateString(),
                    'next_invoice_subtotal_before_kobo' => $change->before->next?->subtotal->kobo,
                    'next_invoice_subtotal_after_kobo' => $change->after->next?->subtotal->kobo,
                ]);
                $this->auditMove($locked, $move, $actor);

                if ($locked->isReturn()) {
                    $this->notices->tell($locked, 'handover.returned', __(':landlord took back :what', ['landlord' => $receiver->name, 'what' => $what]), [
                        __(':landlord accepted the return: :what moved to its account for good, with their units, tenants and leases. The bills, receipts and payments you issued stay with you, and you collect what is still owed on them.', ['landlord' => $receiver->name, 'what' => $what]),
                    ], [
                        __(':what returned from :agency to your account for good, with their units, tenants and leases. Your GoProperty bill goes from :before to :after a month (before VAT) from :date.', ['what' => $what, 'agency' => $from->name, 'before' => $change->before->amount->format(), 'after' => $change->after->amount->format(), 'date' => $change->from->format('j F Y')]),
                    ]);
                } else {
                    $this->notices->tell($locked, 'handover.accepted', __(':agency now manages :what', ['agency' => $receiver->name, 'what' => $what]), [
                        __(':agency accepted your offer: :what moved to its account with their units, tenants and leases. You follow them in the owner portal. Bills, receipts and payments you issued stay with you.', ['agency' => $receiver->name, 'what' => $what]),
                    ], [
                        __('You now manage :what for :landlord. Your GoProperty bill goes from :before to :after a month (before VAT) from :date.', ['what' => $what, 'landlord' => $from->name, 'before' => $change->before->amount->format(), 'after' => $change->after->amount->format(), 'date' => $change->from->format('j F Y')]),
                    ]);
                }

                $this->tenantsMovedOut($from, $move);

                return $locked;
            }));
        } catch (BuildingMoveFailed $failed) {
            $attempt->discard();

            throw new HandoverRefused(__('The buildings could not be moved, so nothing changed: :reason', ['reason' => $failed->getMessage()]), previous: $failed);
        } catch (Throwable $failure) {
            $attempt->discard();

            throw $failure;
        }
    }

    /**
     * The receiving organization (the current one) declines: nothing moves.
     *
     * @throws HandoverRefused
     */
    public function decline(User $actor, ManagementHandover $handover): ManagementHandover
    {
        Gate::forUser($actor)->authorize('respond', $handover);
        $receiver = $this->context->get();
        $from = $this->offeringOrganization($handover);

        return DB::transaction(fn (): ManagementHandover => $this->context->run($from, function () use ($actor, $handover, $receiver, $from): ManagementHandover {
            $locked = $this->lockAddressedTo($handover, $receiver);
            $this->ensureCanMove($locked, HandoverStatus::Declined);

            $locked->forceFill([
                'status' => HandoverStatus::Declined,
                'responded_by_user_id' => $actor->id,
                'responded_at' => now(),
            ])->save();

            $this->closeInvitation($locked, $actor);

            if ($locked->isReturn()) {
                $this->audit('handover.declined', $locked, $actor, "{$receiver->name} declined the return of the buildings");
                $this->notices->tell($locked, 'handover.declined', __(':landlord declined the return', ['landlord' => $receiver->name]), [
                    __(':landlord declined the return. Nothing moved; the buildings stay with :agency.', ['landlord' => $receiver->name, 'agency' => $from->name]),
                ]);
            } else {
                $this->audit('handover.declined', $locked, $actor, "{$receiver->name} declined to manage the buildings");
                $this->notices->tell($locked, 'handover.declined', __(':agency declined the handover', ['agency' => $receiver->name]), [
                    __(':agency declined your offer. Nothing moved; your buildings stay with :landlord.', ['agency' => $receiver->name, 'landlord' => $from->name]),
                ]);
            }

            return $locked;
        }));
    }

    /**
     * The offering organization (the current one) withdraws its offer
     * before an answer; the link stops working.
     *
     * @throws HandoverRefused
     */
    public function cancel(User $actor, ManagementHandover $handover): ManagementHandover
    {
        Gate::forUser($actor)->authorize('cancel', $handover);

        return DB::transaction(function () use ($actor, $handover): ManagementHandover {
            $locked = $this->lockOwn($handover);
            $this->ensureCanMove($locked, HandoverStatus::Cancelled);

            $locked->forceFill(['status' => HandoverStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->closeInvitation($locked, null);
            $this->audit('handover.cancelled', $locked, $actor, ($locked->isReturn() ? 'Withdrew the return offer to ' : 'Withdrew the handover offer to ').$locked->toOrganization->name);
            $this->notices->tell($locked, 'handover.cancelled', __(':landlord withdrew its handover offer', ['landlord' => $locked->organization->name]), [
                __('You withdrew the offer to :agency. Nothing moved.', ['agency' => $locked->toOrganization->name]),
            ], [
                $locked->isReturn()
                    ? __(':agency withdrew its offer to return buildings to you. Nothing moved.', ['agency' => $locked->organization->name])
                    : __(':landlord withdrew its offer of buildings to manage. Nothing moved.', ['landlord' => $locked->organization->name]),
            ]);

            return $locked;
        });
    }

    /**
     * Records that a pending offer ran out unanswered; for the hourly job
     * and the opening of a link past its date. Runs as the system, in the
     * offering organization's context.
     *
     * @throws HandoverRefused when it is not pending, or not yet due
     */
    public function expire(ManagementHandover $handover): ManagementHandover
    {
        $this->requireOfferingContext($handover);

        return DB::transaction(function () use ($handover): ManagementHandover {
            $locked = $this->lockOwn($handover);

            if (! $locked->status->canMoveTo(HandoverStatus::Expired)) {
                throw new HandoverRefused($this->closedMessage($locked));
            }

            if (! $locked->isPastExpiry()) {
                throw new HandoverRefused(__('This offer is still open until :date.', ['date' => $this->date($locked->expires_at)]));
            }

            $locked->forceFill(['status' => HandoverStatus::Expired])->save();

            $this->closeInvitation($locked, null);
            $this->audit('handover.expired', $locked, null, "The handover offer to {$locked->toOrganization->name} expired unanswered");
            $this->notices->tell($locked, 'handover.expired', __('A handover offer expired'), [
                __('Your offer to :agency expired unanswered. Nothing moved; you can offer the buildings again.', ['agency' => $locked->toOrganization->name]),
            ], [
                __('The offer from :landlord expired unanswered. Nothing moved.', ['landlord' => $locked->organization->name]),
            ]);

            return $locked;
        });
    }

    /**
     * Either side (the current organization) ends an active handover with
     * notice: the agency keeps managing until the end date, notice days
     * from now (BILLING_PAYMENTS.md § 7.6).
     *
     * @throws HandoverRefused
     */
    public function giveNotice(User $actor, ManagementHandover $handover): ManagementHandover
    {
        Gate::forUser($actor)->authorize('giveNotice', $handover);
        $side = $this->context->get();
        $from = $this->offeringOrganization($handover);

        return DB::transaction(fn (): ManagementHandover => $this->context->run($from, function () use ($actor, $handover, $side): ManagementHandover {
            $locked = $this->lockSide($handover, $side);
            $this->ensureCanMove($locked, HandoverStatus::Ending);

            $locked->forceFill([
                'status' => HandoverStatus::Ending,
                'notice_given_by_organization_id' => $side->id,
                'notice_given_by_user_id' => $actor->id,
                'notice_given_at' => now(),
                'ends_at' => now()->addDays($locked->notice_days),
            ])->save();

            $other = $side->id === $locked->organization_id ? $locked->toOrganization : $locked->organization;
            $this->audit('handover.notice_given', $locked, $actor, "{$side->name} gave notice to end the management on {$this->date($locked->ends_at)}", [
                'notice_given_by_organization_id' => $side->id,
                'ends_at' => $locked->ends_at?->toIso8601String(),
            ]);
            $this->notices->tell($locked, 'handover.notice_given', __(':organization gave notice: management ends on :date', ['organization' => $side->name, 'date' => $this->date($locked->ends_at)]), [
                __(':organization gave notice to end the management of the buildings with :other. :agency keeps managing them until :date; then they return to :landlord with their units, tenants and leases.', [
                    'organization' => $side->name,
                    'other' => $other->name,
                    'agency' => $locked->toOrganization->name,
                    'date' => $this->date($locked->ends_at),
                    'landlord' => $locked->landlordOrganization->name,
                ]),
            ]);

            return $locked;
        }));
    }

    /**
     * The side that gave notice (the current organization) withdraws it,
     * before the end date: management goes on.
     *
     * @throws HandoverRefused
     */
    public function withdrawNotice(User $actor, ManagementHandover $handover): ManagementHandover
    {
        Gate::forUser($actor)->authorize('withdrawNotice', $handover);
        $side = $this->context->get();
        $from = $this->offeringOrganization($handover);

        return DB::transaction(fn (): ManagementHandover => $this->context->run($from, function () use ($actor, $handover, $side): ManagementHandover {
            $locked = $this->lockSide($handover, $side);

            if ($locked->status === HandoverStatus::Ending && $locked->notice_given_by_organization_id !== $side->id) {
                // BILLING_PAYMENTS.md § 7.2: "only the side that gave it".
                throw new HandoverRefused(__('Only the side that gave notice can withdraw it.'));
            }

            $this->ensureCanMove($locked, HandoverStatus::Active);

            if ($locked->ends_at === null || ! $locked->ends_at->isFuture()) {
                throw new HandoverRefused(__('The notice ran out on :date: the buildings are going back.', ['date' => $this->date($locked->ends_at)]));
            }

            $locked->forceFill([
                'status' => HandoverStatus::Active,
                'notice_given_by_organization_id' => null,
                'notice_given_by_user_id' => null,
                'notice_given_at' => null,
                'ends_at' => null,
            ])->save();

            $this->audit('handover.notice_withdrawn', $locked, $actor, "{$side->name} withdrew its notice: management goes on");
            $this->notices->tell($locked, 'handover.notice_withdrawn', __(':organization withdrew its notice', ['organization' => $side->name]), [
                __(':organization withdrew its notice: :agency goes on managing the buildings.', ['organization' => $side->name, 'agency' => $locked->toOrganization->name]),
            ]);

            return $locked;
        }));
    }

    /**
     * The end date passed: the buildings and their live records move back
     * to the landlord's organization, in one transaction; what the agency
     * issued meanwhile stays with it as history. For the hourly job, in the
     * offering organization's context.
     *
     * @throws HandoverRefused when it is not ending, or not yet due
     * @throws BuildingMoveFailed when a module cannot move its records back (nothing changed)
     */
    public function end(ManagementHandover $handover): ManagementHandover
    {
        $this->requireOfferingContext($handover);
        $attempt = new MoveAttempt;

        try {
            return DB::transaction(function () use ($handover, $attempt): ManagementHandover {
                $locked = $this->lockOwn($handover);

                if (! $locked->status->canMoveTo(HandoverStatus::Ended)) {
                    throw new HandoverRefused($this->closedMessage($locked));
                }

                if ($locked->ends_at === null || $locked->ends_at->isFuture()) {
                    throw new HandoverRefused(__('Management goes on until :date.', ['date' => $this->date($locked->ends_at)]));
                }

                $agency = $locked->toOrganization;
                $landlord = $locked->landlordOrganization;
                $propertyIds = $locked->propertyIds();

                $move = $attempt->move = $this->moves->run(
                    $agency,
                    $landlord,
                    MoveDirection::Return,
                    $propertyIds,
                    $locked->id,
                    landlordOrganization: $landlord,
                    landlord: $locked->initiatedBy,
                    landlordOwnerProfileId: $locked->owner_profile_id,
                    restoreOwnerProfileIds: $locked->buildings->mapWithKeys(static fn (HandoverBuilding $building): array => [$building->property_id => $building->previous_owner_profile_id])->all(),
                    knownTenants: $this->origins->between($agency, $landlord),
                );

                $locked->forceFill(['status' => HandoverStatus::Ended, 'ended_at' => now()])->save();

                $what = $this->describe(count($propertyIds), $locked->unitCount());
                $this->audit('handover.ended', $locked, null, "Management by {$agency->name} ended: {$what} returned to {$landlord->name}", ['property_ids' => $propertyIds]);
                $this->auditMove($locked, $move, null);
                $this->notices->tell($locked, 'handover.ended', __('Management ended: :what returned to :landlord', ['what' => $what, 'landlord' => $landlord->name]), [
                    __(':what returned to your account with their units, tenants and leases. What :agency issued meanwhile (bills, receipts, payments) stays with it.', ['what' => $what, 'agency' => $agency->name]),
                ], [
                    __('Your management of :what for :landlord ended: they returned to its account. The bills, receipts and payments you issued stay with you.', ['what' => $what, 'landlord' => $landlord->name]),
                ]);

                return $locked;
            });
        } catch (Throwable $failure) {
            $attempt->discard();

            throw $failure;
        }
    }

    /**
     * Expires every pending offer of the current organization past its 14
     * days; safe to run again.
     *
     * @return int how many expired
     */
    public function expireDue(): int
    {
        $expired = 0;

        foreach (ManagementHandover::query()->where('status', HandoverStatus::Pending)->where('expires_at', '<=', now())->orderBy('id')->get() as $handover) {
            try {
                $this->expire($handover);
                $expired++;
            } catch (HandoverRefused) {
                // Answered or withdrawn meanwhile: nothing to do.
            }
        }

        return $expired;
    }

    /**
     * Ends every handover of the current organization whose notice ran out;
     * safe to run again (an ended handover is no longer ending). A handover
     * whose move fails stays ending and is tried again next hour.
     *
     * @return int how many ended
     */
    public function endDue(): int
    {
        $ended = 0;

        foreach (ManagementHandover::query()->where('status', HandoverStatus::Ending)->where('ends_at', '<=', now())->orderBy('id')->get() as $handover) {
            try {
                $this->end($handover);
                $ended++;
            } catch (HandoverRefused) {
                // Notice withdrawn meanwhile: nothing to do.
            } catch (BuildingMoveFailed $failed) {
                report($failed);
            }
        }

        return $ended;
    }

    /**
     * Locks the offering organization (one offer at a time, so a building is
     * never offered twice at once) and checks the buildings are its own,
     * active and in no open handover.
     *
     * @param  list<int>  $propertyIds
     * @return array<int, PropertySummary> by id
     *
     * @throws HandoverRefused
     */
    private function lockOfferable(Organization $from, array $propertyIds): array
    {
        Organization::query()->whereKey($from->id)->lockForUpdate()->first();

        $buildings = $this->properties->findMany($propertyIds);

        foreach ($propertyIds as $propertyId) {
            $building = $buildings[$propertyId] ?? null;

            if ($building === null || $building->archived) {
                throw new HandoverRefused(__('Only your active buildings can be handed over.'));
            }
        }

        if (($busy = $this->offerable->inOpenHandover($propertyIds)) !== []) {
            throw new HandoverRefused(__(':building is already in an open handover.', ['building' => $buildings[$busy[0]]->name]));
        }

        return $buildings;
    }

    /**
     * The offer's buildings, the moving tenants' credit lines and the
     * invitation carrying its link.
     *
     * @param  list<int>  $propertyIds
     * @param  array<int, PropertySummary>  $buildings
     * @param  array<int, int>  $units
     * @return array{Invitation, string} the invitation and its token
     */
    private function recordOffer(ManagementHandover $handover, User $actor, Organization $target, Organization $from, array $propertyIds, array $buildings, array $units): array
    {
        foreach ($propertyIds as $propertyId) {
            $building = $buildings[$propertyId];
            $row = new HandoverBuilding;
            $row->forceFill([
                'management_handover_id' => $handover->id,
                'property_id' => $propertyId,
                'property_name' => Str::limit($building->name, 160, ''),
                'property_address' => Str::limit($building->address, 300, ''),
                'unit_count' => $units[$propertyId] ?? 0,
            ])->save();
        }

        $this->credits->refresh($handover, $propertyIds);

        $token = Str::random(48);
        $invitation = new Invitation;
        $invitation->forceFill([
            'kind' => InvitationKind::ManagementHandover,
            'channel' => InvitationChannel::Email,
            'roles' => [],
            'target_organization_id' => $target->id,
            'management_handover_id' => $handover->id,
            'token_hash' => HandoverLinks::hash($token),
            'status' => HandoverStatus::Pending->invitationStatus(),
            'expires_at' => $handover->expires_at,
            'sent_at' => now(),
            'send_count' => 1,
            'invited_by_user_id' => $actor->id,
        ])->save();

        $handover->setRelation('organization', $from)->setRelation('toOrganization', $target);

        return [$invitation, $token];
    }

    /**
     * The landlord a one-way return goes to (§ 7.1, P04-04 safeguard): the
     * account linked to the buildings' owner record in the offering
     * organization, the same for every building, which must be an owner of
     * $target. Read in the offering organization's context.
     *
     * @param  array<int, PropertySummary>  $buildings
     *
     * @throws HandoverRefused
     */
    private function returnOwner(array $buildings, Organization $target): User
    {
        $userIds = [];

        foreach ($buildings as $building) {
            if ($building->ownerProfileId === null) {
                throw new HandoverRefused(__(':building has no owner on record. Add its owner, invite them to the owner portal, then offer the return.', ['building' => $building->name]));
            }

            if ($building->ownerUserId === null) {
                throw new HandoverRefused(__(':owner, the owner of :building, has no GoProperty account linked yet. Invite them to the owner portal first (Owners → Invite to the owner portal); once they accept, offer the return.', ['owner' => $building->ownerName ?? __('The owner'), 'building' => $building->name]));
            }

            $userIds[$building->ownerUserId] = true;
        }

        if (count($userIds) !== 1) {
            throw new HandoverRefused(__('These buildings belong to different owners. Return one owner\'s buildings at a time.'));
        }

        $landlord = User::query()->find(array_key_first($userIds));

        if ($landlord === null || ! HandoverTargets::ownedBy($landlord->id, $target)) {
            throw new HandoverRefused(__('Buildings return only to their owner\'s own organization, and :organization is not one their owner runs. Check the web address, or ask the owner for their organization\'s.', ['organization' => $target->name]));
        }

        return $landlord;
    }

    /** Once the move is committed, the tenants' records left behind may archive themselves (BILLING_PAYMENTS.md § 7.4). */
    private function tenantsMovedOut(Organization $from, BuildingMove $move): void
    {
        $tenantIds = array_map('intval', array_keys($move->tenantMap()));

        if ($tenantIds !== []) {
            DB::afterCommit(static fn () => event(new MovedTenantsChanged($from->id, $tenantIds)));
        }
    }

    /** The offering organization of a handover (the row's owner). */
    private function offeringOrganization(ManagementHandover $handover): Organization
    {
        return Organization::query()->findOrFail($handover->organization_id);
    }

    /** The row, locked, inside the offering organization's context. */
    private function lockOwn(ManagementHandover $handover): ManagementHandover
    {
        return ManagementHandover::query()
            ->with(['organization', 'toOrganization', 'landlordOrganization', 'initiatedBy', 'buildings'])
            ->whereKey($handover->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The row, locked, if it is addressed to the receiving organization (the
     * policy checked this too).
     *
     * @throws HandoverRefused
     */
    private function lockAddressedTo(ManagementHandover $handover, Organization $receiver): ManagementHandover
    {
        $locked = ManagementHandover::query()->with(['organization', 'toOrganization', 'landlordOrganization', 'initiatedBy', 'buildings'])->whereKey($handover->id)->lockForUpdate()->first();

        if ($locked === null || $locked->to_organization_id !== $receiver->id) {
            throw new HandoverRefused(__('This handover was not offered to your organization.'));
        }

        return $locked;
    }

    /**
     * The row, locked, if $side is one of its two organizations.
     *
     * @throws HandoverRefused
     */
    private function lockSide(ManagementHandover $handover, Organization $side): ManagementHandover
    {
        $locked = ManagementHandover::query()->with(['organization', 'toOrganization', 'landlordOrganization'])->whereKey($handover->id)->lockForUpdate()->first();

        if ($locked === null || ! in_array($side->id, [$locked->organization_id, $locked->to_organization_id], true)) {
            throw new HandoverRefused(__('This handover is not your organization\'s.'));
        }

        return $locked;
    }

    /** @throws HandoverRefused */
    private function ensureCanMove(ManagementHandover $handover, HandoverStatus $next): void
    {
        if (! $handover->status->canMoveTo($next)) {
            throw new HandoverRefused($this->closedMessage($handover));
        }

        if ($handover->status === HandoverStatus::Pending && $handover->isPastExpiry()) {
            throw new HandoverRefused(__('This offer expired on :date.', ['date' => $this->date($handover->expires_at)]));
        }
    }

    private function requireOfferingContext(ManagementHandover $handover): void
    {
        if (! $this->context->has() || $this->context->id() !== $handover->organization_id) {
            throw new LogicException('Run this inside the offering organization\'s context.');
        }
    }

    private function closedMessage(ManagementHandover $handover): string
    {
        return match ($handover->status) {
            HandoverStatus::Pending => __('This offer is still waiting for an answer.'),
            HandoverStatus::Active => __('The buildings are being managed under this handover.'),
            HandoverStatus::Ending => __('Notice was given: management ends on :date.', ['date' => $this->date($handover->ends_at)]),
            HandoverStatus::Declined => __('This offer was declined.'),
            HandoverStatus::Cancelled => __('This offer was withdrawn.'),
            HandoverStatus::Expired => __('This offer expired on :date.', ['date' => $this->date($handover->expires_at)]),
            HandoverStatus::Ended => __('This management ended on :date.', ['date' => $this->date($handover->ended_at)]),
            HandoverStatus::Returned => __('These buildings were returned to :landlord on :date, for good: a return has no notice and no end. To have them managed again, the landlord offers them with a new handover.', ['landlord' => $handover->toOrganization->name, 'date' => $this->date($handover->accepted_at)]),
        };
    }

    private function date(?CarbonInterface $date): string
    {
        return $date !== null ? $date->toImmutable()->timezone('Africa/Lagos')->format('j F Y') : __('its date');
    }

    private function describe(int $buildings, int $units): string
    {
        return trans_choice('{1} :count building|[2,*] :count buildings', $buildings, ['count' => $buildings]).' ('.trans_choice('{0} no units|{1} :count unit|[2,*] :count units', $units, ['count' => $units]).')';
    }

    /** The invitation that carries the link follows the offer; its link no longer works. */
    private function closeInvitation(ManagementHandover $handover, ?User $acceptedBy): void
    {
        $invitation = Invitation::query()
            ->where('kind', InvitationKind::ManagementHandover)
            ->where('management_handover_id', $handover->id)
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            return;
        }

        $accepted = $handover->status->wasAccepted();
        $invitation->forceFill(array_filter([
            'status' => $handover->status->invitationStatus(),
            'accepted_at' => $accepted ? now() : null,
            'accepted_by_user_id' => $accepted ? $acceptedBy?->id : null,
            'cancelled_at' => $handover->status === HandoverStatus::Cancelled ? now() : null,
        ], static fn (mixed $value): bool => $value !== null))->save();
    }

    /**
     * What moved, written to the audit log of both organizations (each
     * entry has its organization as subject) and the handover's.
     */
    private function auditMove(ManagementHandover $handover, BuildingMove $move, ?User $actor): void
    {
        $records = [];

        foreach ($move->moved() as $model => $ids) {
            $records[class_basename($model)] = count($ids);
        }

        ksort($records);
        $properties = [
            'handover_id' => $handover->id,
            'direction' => $move->direction->value,
            'from_organization_id' => $move->from->id,
            'to_organization_id' => $move->to->id,
            'property_ids' => $move->propertyIds,
            'records' => $records,
            'counts' => $move->counts(),
            'tenant_map' => array_map(static fn (int $from, int $to): array => ['from' => $from, 'to' => $to], array_keys($move->tenantMap()), $move->tenantMap()),
        ];

        foreach ([[$move->from, 'handover.records_moved_out', "Buildings and their live records moved to {$move->to->name}"], [$move->to, 'handover.records_moved_in', "Buildings and their live records moved in from {$move->from->name}"]] as [$organization, $event, $description]) {
            $entry = activity(self::LOG)->event($event)->performedOn($organization)->withProperties($properties);

            if ($actor !== null) {
                $entry->causedBy($actor);
            }

            $entry->log($description);
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, ManagementHandover $handover, ?User $actor, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($handover)
            ->withProperties([
                'handover_id' => $handover->id,
                'from_organization_id' => $handover->organization_id,
                'to_organization_id' => $handover->to_organization_id,
                'status' => $handover->status->value,
                ...$properties,
            ]);

        if ($actor !== null) {
            $entry->causedBy($actor);
        }

        $entry->log($description);
    }
}
