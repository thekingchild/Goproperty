<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Documents\DocumentNumberer;
use App\Documents\DocumentType;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Enums\DepositEntryType;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Events\LeaseDrafted;
use Modules\Leasing\Events\LeaseEndingSoon;
use Modules\Leasing\Events\LeaseExpired;
use Modules\Leasing\Events\LeaseRenewed;
use Modules\Leasing\Events\LeaseTerminated;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitSummary;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The only writer of leases and their deposit entries (AGENT.md § 3 rule
 * 5; ARCHITECTURE.md § 6.2–6.3; DATABASE_SCHEMA.md § 3). Every move of
 * the state machine (LeaseStatus) happens here, inside a transaction run
 * as DB::transaction($callback, 3) (a deadlock re-runs it, AGENT.md § 4):
 * a move the state machine does not allow is refused (LeaseRefused) and
 * changes nothing.
 *
 * - draft(): a lease for a tenant and a unit. While drafts wait for a
 *   vacant unit it is `reserved` for them; each draft holds it until
 *   `reserved_until` (today + the organization's `leasing.reservation_days`,
 *   default 14), after which leases:expire-and-flag expires the draft and
 *   releases the unit. A draft may also wait for an occupied unit (the
 *   next tenant); it can be activated once that lease ends.
 * - activate(): under the unit's row lock (UnitDirectory::lock, so two
 *   activations on one unit wait for each other), refused while another
 *   lease is active on the unit; gives the contract number
 *   (DocumentNumberer, `lease_contract`) and the first billing date
 *   (CycleCalculator), records the deposit taken, and lets the unit
 *   (occupied). The database also keeps one active lease per unit.
 * - renew(): a new draft for the same tenant and unit, referencing the
 *   active lease (`renewal_of_lease_id`); activating it marks the old one
 *   `renewed` and carries its deposit over.
 * - terminate(): ends an active lease early, or cancels a draft, with a
 *   reason. expire(): an active lease after its end date, a draft after its
 *   hold (leases:expire-and-flag).
 *
 * Unit statuses are the Properties module's: each change sends a
 * Modules\Leasing\Events event inside the transaction, carrying the status
 * the unit must have now (worked out here from every lease of the unit,
 * under its row lock), and the Properties module applies it. User actions
 * check LeasePolicy for the acting user (so the suspension and impersonation
 * guards apply); everything is audited (log `leases`, the lease as subject).
 *
 * Lock order, everywhere: tenant row, then unit row, then lease rows.
 */
final readonly class LeaseService
{
    public const string LOG = 'leases';

    public const string RESERVATION_DAYS_SETTING = 'leasing.reservation_days';

    public const int DEFAULT_RESERVATION_DAYS = 14;

    public const string ENDING_SOON_DAYS_SETTING = 'leasing.ending_soon_days';

    public const int DEFAULT_ENDING_SOON_DAYS = 60;

    /** Lease fields named in the `lease.updated` audit entry. */
    private const array AUDITED = ['billing_cycle', 'start_date', 'end_date', 'rent_amount_kobo', 'deposit_kobo', 'due_day_offset', 'paid_until', 'auto_renew', 'notes'];

    public function __construct(
        private UnitDirectory $units,
        private TenantDirectory $tenants,
        private BuildingAccess $access,
        private CycleCalculator $calculator,
        private DocumentNumberer $numberer,
        private LeaseTerms $terms,
        private SettingsStore $settings,
    ) {}

    /**
     * A new draft lease (form fields: tenantId, unitId and the terms).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function draft(User $by, array $input): Lease
    {
        Gate::forUser($by)->authorize('create', Lease::class);

        $parties = $this->terms->parties($input);
        $terms = $this->terms->validated($input);

        // The tenant must be one the member sees (an assigned agent's tenants).
        if ($this->tenants->find($parties['tenantId'], $by) === null) {
            throw ValidationException::withMessages(['tenantId' => __('Choose one of your tenants.')]);
        }

        return $this->transaction(function () use ($by, $parties, $terms): Lease {
            $tenant = $this->tenants->lockActive($parties['tenantId'])
                ?? throw ValidationException::withMessages(['tenantId' => __('This tenant is archived. Restore them before giving them a lease.')]);
            $unit = $this->lockUnitFor($by, $parties['unitId']);

            if ($unit->status === UnitDirectory::UNDER_MAINTENANCE || $unit->status === UnitDirectory::UNAVAILABLE) {
                throw ValidationException::withMessages(['unitId' => __(':label is :status. Make it vacant before leasing it.', [
                    'label' => $unit->label,
                    'status' => mb_strtolower(__($unit->statusLabel)),
                ])]);
            }

            $lease = $this->newDraft($by, $unit, $tenant->id, $terms);
            $this->audit('lease.drafted', $lease, $by, 'Lease drafted', ['unit_id' => $unit->id, 'tenant_profile_id' => $tenant->id, 'reserved_until' => $lease->reserved_until?->toDateString()]);
            event(new LeaseDrafted(...$this->eventIds($lease, $this->unitStatusAfter($unit))));

            return $lease;
        });
    }

    /**
     * Change a draft's terms (its tenant and unit stay: cancel it and draft
     * another for those).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $by, Lease $lease, array $input): Lease
    {
        Gate::forUser($by)->authorize('update', $lease);

        $previous = $lease->renewalOf;
        $terms = $this->terms->validated($input, $previous?->end_date);

        return $this->transaction(function () use ($by, $lease, $terms, $previous): Lease {
            $lease = $this->lockLease($lease->id);

            if ($lease->status !== LeaseStatus::Draft) {
                throw LeaseRefused::because(__('Only a draft lease can be changed; this one is :status.', ['status' => mb_strtolower(__($lease->status->label()))]));
            }

            $this->fillTerms($lease, $terms, keepDeposit: $previous !== null);
            $changed = array_values(array_intersect(self::AUDITED, array_keys($lease->getDirty())));

            if ($changed !== []) {
                $lease->save();
                $this->audit('lease.updated', $lease, $by, 'Draft lease changed', ['changed' => $changed]);
            }

            return $lease;
        });
    }

    /**
     * A renewal: a new draft lease for the same tenant and unit, referencing
     * the active lease (ARCHITECTURE.md § 6.3: a new record, new terms said
     * explicitly). It starts after the active lease ends; the deposit held
     * is carried over when it is activated.
     *
     * @param  array<string, mixed>  $input  the terms
     *
     * @throws ValidationException
     */
    public function renew(User $by, Lease $lease, array $input): Lease
    {
        Gate::forUser($by)->authorize('renew', $lease);

        $terms = $this->terms->validated($input, $lease->end_date);

        return $this->transaction(function () use ($by, $lease, $terms): Lease {
            $unit = $this->units->lock($lease->unit_id)
                ?? throw LeaseRefused::because(__('This lease\'s unit is no longer available.'));
            $previous = $this->lockLease($lease->id);
            $this->refuseUnlessMove($previous, LeaseStatus::Renewed, __('renewed'));

            if ($previous->renewals()->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])->exists()) {
                throw LeaseRefused::because(__('This lease already has a renewal. Open it, or cancel it first.'));
            }

            $renewal = $this->newDraft($by, $unit, $previous->tenant_profile_id, $terms, $previous);
            $this->audit('lease.drafted', $renewal, $by, 'Renewal drafted', ['unit_id' => $unit->id, 'tenant_profile_id' => $previous->tenant_profile_id, 'renewal_of_lease_id' => $previous->id, 'reserved_until' => $renewal->reserved_until?->toDateString()]);
            event(new LeaseDrafted(...$this->eventIds($renewal, $this->unitStatusAfter($unit))));

            return $renewal;
        });
    }

    /**
     * Activate a draft: the unit is let. Refused while another lease is
     * active on the unit (a renewal replaces the lease it renews, which
     * becomes `renewed`).
     *
     * @throws ValidationException
     */
    public function activate(User $by, Lease $lease): Lease
    {
        Gate::forUser($by)->authorize('activate', $lease);

        try {
            return $this->transaction(function () use ($by, $lease): Lease {
                // The unit first: a second activation on this unit waits here.
                $unit = $this->units->lock($lease->unit_id)
                    ?? throw LeaseRefused::because(__('The unit of this lease has been archived. Restore it before activating the lease.'));
                $lease = $this->lockLease($lease->id);
                $this->refuseUnlessMove($lease, LeaseStatus::Active, __('activated'));

                if ($unit->propertyArchived) {
                    throw LeaseRefused::because(__(':building is archived. Restore it before letting its units.', ['building' => $unit->propertyName]));
                }

                if ($unit->status === UnitDirectory::UNDER_MAINTENANCE || $unit->status === UnitDirectory::UNAVAILABLE) {
                    throw LeaseRefused::because(__(':label is :status. Make it vacant before letting it.', ['label' => $unit->label, 'status' => mb_strtolower(__($unit->statusLabel))]));
                }

                $tenant = $this->tenants->find($lease->tenant_profile_id);

                if ($tenant === null || $tenant->archived) {
                    throw LeaseRefused::because(__('This lease\'s tenant is archived. Restore them before activating the lease.'));
                }

                $previous = $this->previousForActivation($lease);
                $other = Lease::query()->where('unit_id', $lease->unit_id)->where('status', LeaseStatus::Active)
                    ->whereKeyNot($lease->id)->lockForUpdate()->first();

                if ($other !== null && $other->id !== $previous?->id) {
                    throw LeaseRefused::because(__(':label is already let (:contract). End that lease before activating this one.', ['label' => $unit->label, 'contract' => $other->reference()]));
                }

                $renewed = $previous !== null && $previous->status === LeaseStatus::Active;

                if ($renewed) {
                    // Before this lease becomes active: the database keeps one active lease per unit.
                    $previous->status = LeaseStatus::Renewed;
                    $previous->save();
                }

                $now = CarbonImmutable::now();
                $lease->status = LeaseStatus::Active;
                $lease->activated_at = $now;
                $lease->reserved_until = null;
                $lease->contract_number = $this->numberer->next(DocumentType::LeaseContract, $now);
                $lease->next_billing_date = $this->firstBillingDate($lease);
                $lease->save();

                $this->recordDepositOnActivation($by, $lease, $previous);

                $this->audit('lease.activated', $lease, $by, 'Lease activated', [
                    'contract_number' => $lease->contract_number,
                    'next_billing_date' => $lease->next_billing_date?->toDateString(),
                ] + ($previous !== null ? ['renewal_of_lease_id' => $previous->id] : []));

                $unitStatus = $this->unitStatusAfter($unit);

                if ($renewed) {
                    $this->audit('lease.renewed', $previous, $by, 'Lease renewed', ['renewal_lease_id' => $lease->id, 'renewal_contract_number' => $lease->contract_number]);
                    event(new LeaseRenewed(...[...$this->eventIds($previous, null), 'renewalLeaseId' => $lease->id]));
                }

                event(new LeaseActivated(...$this->eventIds($lease, $unitStatus)));

                return $lease;
            });
        } catch (UniqueConstraintViolationException) {
            // Another lease became active on the unit meanwhile (the database's own check).
            throw LeaseRefused::because(__('This unit has just been let under another lease. Nothing was changed.'));
        }
    }

    /**
     * End an active lease early, or cancel a draft, with a reason.
     *
     * @throws ValidationException
     */
    public function terminate(User $by, Lease $lease, string $reason): Lease
    {
        Gate::forUser($by)->authorize('terminate', $lease);

        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:500']], [
            'reason.required' => __('Say why the lease ends.'),
        ])->validate();

        return $this->transaction(function () use ($by, $lease, $reason): Lease {
            $unit = $this->units->lock($lease->unit_id);
            $lease = $this->lockLease($lease->id);
            $this->refuseUnlessMove($lease, LeaseStatus::Terminated, __('ended'));
            $wasDraft = $lease->status === LeaseStatus::Draft;

            $lease->status = LeaseStatus::Terminated;
            $lease->terminated_at = CarbonImmutable::now();
            $lease->termination_reason = $reason;
            $lease->reserved_until = null;
            $lease->save();

            $this->audit($wasDraft ? 'lease.draft_cancelled' : 'lease.terminated', $lease, $by, $wasDraft ? 'Draft lease cancelled' : 'Lease terminated', ['reason' => $reason]);
            event(new LeaseTerminated(...[...$this->eventIds($lease, $unit !== null ? $this->unitStatusAfter($unit) : null), 'wasDraft' => $wasDraft]));

            return $lease;
        });
    }

    /**
     * Expire an active lease whose end date has passed, or a draft whose
     * hold on its unit has run out (leases:expire-and-flag). Refused before
     * then.
     *
     * @throws ValidationException
     */
    public function expire(Lease $lease, ?User $by = null): Lease
    {
        return $this->transaction(function () use ($by, $lease): Lease {
            $unit = $this->units->lock($lease->unit_id);
            $lease = $this->lockLease($lease->id);
            $this->refuseUnlessMove($lease, LeaseStatus::Expired, __('expired'));
            $today = LeaseDates::today();
            $wasDraft = $lease->status === LeaseStatus::Draft;

            if ($wasDraft && ($lease->reserved_until === null || ! $lease->reserved_until->lessThan($today))) {
                throw LeaseRefused::because(__('This draft holds its unit until :date.', ['date' => $lease->reserved_until?->format('d/m/Y') ?? '—']));
            }

            if (! $wasDraft && ! $lease->end_date->lessThan($today)) {
                throw LeaseRefused::because(__('This lease runs until :date.', ['date' => $lease->end_date->format('d/m/Y')]));
            }

            $lease->status = LeaseStatus::Expired;
            $lease->expired_at = CarbonImmutable::now();
            $lease->save();

            $this->audit($wasDraft ? 'lease.reservation_released' : 'lease.expired', $lease, $by, $wasDraft ? 'Draft lease expired: unit released' : 'Lease expired', $wasDraft
                ? ['reserved_until' => $lease->reserved_until->toDateString()]
                : ['end_date' => $lease->end_date->toDateString()]);
            event(new LeaseExpired(...[...$this->eventIds($lease, $unit !== null ? $this->unitStatusAfter($unit) : null), 'wasDraft' => $wasDraft]));

            return $lease;
        });
    }

    /**
     * Flag an active lease that ends within the organization's notice window
     * and has no renewal yet, once (leases:expire-and-flag). Returns whether
     * it was flagged now.
     */
    public function flagEndingSoon(Lease $lease): bool
    {
        return $this->transaction(function () use ($lease): bool {
            $lease = $this->lockLease($lease->id);
            $until = $this->calculator->daysAfter(LeaseDates::today(), $this->endingSoonDays());

            if ($lease->status !== LeaseStatus::Active || $lease->ending_soon_flagged_at !== null || $lease->end_date->greaterThan($until)
                || $lease->renewals()->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])->exists()) {
                return false;
            }

            $lease->ending_soon_flagged_at = CarbonImmutable::now();
            $lease->save();

            $this->audit('lease.ending_soon', $lease, null, 'Lease ends soon', ['end_date' => $lease->end_date->toDateString()]);
            event(new LeaseEndingSoon($lease->organization_id, $lease->id, $lease->property_id, $lease->unit_id, $lease->tenant_profile_id, $lease->end_date));

            return true;
        });
    }

    /**
     * Record what became of an ended lease's deposit (P03 decision 5): the
     * part refunded to the tenant and the part kept (with a note). Records
     * only; no money moves through GoProperty (AGENT.md § 3 rule 8).
     *
     * @param  array<string, mixed>  $input  refunded, kept (naira as typed), note
     *
     * @throws ValidationException
     */
    public function settleDeposit(User $by, Lease $lease, array $input): Lease
    {
        Gate::forUser($by)->authorize('settleDeposit', $lease);

        $values = [
            'refunded' => is_string($input['refunded'] ?? null) && trim($input['refunded']) !== '' ? trim($input['refunded']) : '0',
            'kept' => is_string($input['kept'] ?? null) && trim($input['kept']) !== '' ? trim($input['kept']) : '0',
            'note' => is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null,
        ];
        $amount = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || LeaseTerms::naira($value) === null) {
                $fail(__('Enter an amount in naira, such as 150,000.'));
            }
        };
        Validator::make($values, [
            'refunded' => [$amount],
            'kept' => [$amount],
            'note' => [(LeaseTerms::naira($values['kept']) ?? Money::zero())->isPositive() ? 'required' : 'nullable', 'string', 'max:500'],
        ], ['note.required' => __('Say why part of the deposit is kept.')])->validate();

        $refunded = LeaseTerms::naira($values['refunded']) ?? Money::zero();
        $kept = LeaseTerms::naira($values['kept']) ?? Money::zero();

        return $this->transaction(function () use ($by, $lease, $refunded, $kept, $values): Lease {
            $lease = $this->lockLease($lease->id);

            if (! $lease->status->hasEnded()) {
                throw LeaseRefused::because(__('The deposit is settled once the lease has ended.'), 'refunded');
            }

            $balance = $this->depositBalance($lease);
            $total = $refunded->plus($kept);

            if (! $total->isPositive()) {
                throw LeaseRefused::because(__('Enter the amount refunded, kept, or both.'), 'refunded');
            }

            if ($total->greaterThan($balance)) {
                throw LeaseRefused::because(__('Only :balance of the deposit is held.', ['balance' => $balance->format()]), 'refunded');
            }

            if ($refunded->isPositive()) {
                $this->depositEntry($by, $lease, DepositEntryType::Refunded, $refunded->negate(), null);
            }

            if ($kept->isPositive()) {
                $this->depositEntry($by, $lease, DepositEntryType::Kept, $kept->negate(), $values['note']);
            }

            $this->audit('lease.deposit_settled', $lease, $by, 'Deposit settled', [
                'refunded_kobo' => $refunded->kobo,
                'kept_kobo' => $kept->kobo,
                'balance_kobo' => $balance->minus($total)->kobo,
            ]);

            return $lease;
        });
    }

    /**
     * Billing made the bill for the period starting $periodStart: move the
     * lease to its next period (LeaseBillingSchedule::advance). Idempotent.
     *
     * @throws LogicException when the period is ahead of the lease's next billing date
     */
    public function advanceBilling(int $leaseId, DateTimeInterface $periodStart): ?CarbonImmutable
    {
        return $this->transaction(function () use ($leaseId, $periodStart): ?CarbonImmutable {
            $lease = Lease::query()->whereKey($leaseId)->lockForUpdate()->first();

            if (! $lease instanceof Lease || $lease->next_billing_date === null) {
                return null;
            }

            $start = CarbonImmutable::parse($periodStart->format('Y-m-d'));

            if ($lease->next_billing_date->greaterThan($start)) {
                return $lease->next_billing_date;
            }

            if ($lease->next_billing_date->lessThan($start)) {
                throw new LogicException("Lease {$lease->id} is billed to {$lease->next_billing_date->toDateString()}, not {$start->toDateString()}.");
            }

            $lease->next_billing_date = $this->calculator->nextPeriodStart($lease->start_date, $lease->billing_cycle, $start);
            $lease->save();

            return $lease->next_billing_date;
        });
    }

    /** What is held of the lease's deposit now (the sum of its entries). */
    public function depositBalance(Lease $lease): Money
    {
        $sum = LeaseDepositEntry::query()->where('lease_id', $lease->id)->sum('amount_kobo');

        return Money::kobo((int) $sum);
    }

    /** How many days a draft holds its unit, in the current organization. */
    public function reservationDays(): int
    {
        $days = $this->settings->get(self::RESERVATION_DAYS_SETTING);

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_RESERVATION_DAYS;
    }

    /** How many days before its end an active lease is flagged as ending soon. */
    public function endingSoonDays(): int
    {
        $days = $this->settings->get(self::ENDING_SOON_DAYS_SETTING);

        return is_int($days) && $days > 0 ? $days : self::DEFAULT_ENDING_SOON_DAYS;
    }

    /**
     * The first period start to bill: the lease's start, or, when the tenant
     * has paid up to a day, the first period start after it.
     */
    public function firstBillingDate(Lease $lease): CarbonImmutable
    {
        if ($lease->paid_until === null || $lease->paid_until->lessThan($lease->start_date)) {
            return $lease->start_date;
        }

        return $this->calculator->nextPeriodStart($lease->start_date, $lease->billing_cycle, $lease->paid_until);
    }

    private function newDraft(User $by, UnitSummary $unit, int $tenantId, Terms $terms, ?Lease $renewing = null): Lease
    {
        $lease = new Lease;
        $lease->property_id = $unit->propertyId;
        $lease->unit_id = $unit->id;
        $lease->tenant_profile_id = $tenantId;
        $lease->status = LeaseStatus::Draft;
        $lease->renewal_of_lease_id = $renewing?->id;
        $lease->reserved_until = $this->calculator->daysAfter(LeaseDates::today(), $this->reservationDays());
        $lease->created_by_user_id = $by->id;
        $this->fillTerms($lease, $terms, keepDeposit: false);

        if ($renewing !== null) {
            // The deposit held under the lease it renews is carried over on activation.
            $lease->deposit_kobo = $this->depositBalance($renewing);
        }

        $lease->save();

        return $lease;
    }

    private function fillTerms(Lease $lease, Terms $terms, bool $keepDeposit): void
    {
        $lease->billing_cycle = $terms->cycle;
        $lease->start_date = $terms->start;
        $lease->end_date = $terms->end;
        $lease->rent_amount_kobo = $terms->rent;
        $lease->due_day_offset = $terms->dueDayOffset;
        $lease->paid_until = $terms->paidUntil;
        $lease->auto_renew = $terms->autoRenew;
        $lease->notes = $terms->notes;

        if (! $keepDeposit) {
            $lease->deposit_kobo = $terms->deposit;
        }
    }

    /**
     * The lease a renewal replaces, locked: still active (it becomes
     * renewed) or already expired; null for a lease that renews nothing.
     */
    private function previousForActivation(Lease $lease): ?Lease
    {
        if ($lease->renewal_of_lease_id === null) {
            return null;
        }

        $previous = $this->lockLease($lease->renewal_of_lease_id);

        if ($previous->status !== LeaseStatus::Active && $previous->status !== LeaseStatus::Expired) {
            throw LeaseRefused::because(__('The lease this one renews is :status, so it cannot be renewed. Draft a new lease instead.', ['status' => mb_strtolower(__($previous->status->label()))]));
        }

        if ($previous->renewals()->whereKeyNot($lease->id)->where('status', LeaseStatus::Active)->exists()) {
            throw LeaseRefused::because(__('The lease this one renews has already been renewed.'));
        }

        return $previous;
    }

    private function recordDepositOnActivation(User $by, Lease $lease, ?Lease $previous): void
    {
        if ($previous !== null) {
            $carried = $this->depositBalance($previous);

            if ($carried->isPositive()) {
                $this->depositEntry($by, $previous, DepositEntryType::CarriedOut, $carried->negate(), $lease->contract_number);
                $this->depositEntry($by, $lease, DepositEntryType::CarriedIn, $carried, $previous->contract_number);
            }

            if (! $lease->deposit_kobo->equals($carried)) {
                $lease->deposit_kobo = $carried;
                $lease->save();
            }

            return;
        }

        if ($lease->deposit_kobo->isPositive()) {
            $this->depositEntry($by, $lease, DepositEntryType::Held, $lease->deposit_kobo, null);
        }
    }

    private function depositEntry(?User $by, Lease $lease, DepositEntryType $type, Money $amount, ?string $note): void
    {
        $entry = new LeaseDepositEntry;
        $entry->lease_id = $lease->id;
        $entry->type = $type;
        $entry->amount_kobo = $amount;
        $entry->note = $note;
        $entry->recorded_by_user_id = $by?->id;
        $entry->save();
    }

    /**
     * The unit for a new lease, locked, if the member works on its building.
     *
     * @throws ValidationException
     */
    private function lockUnitFor(User $by, int $unitId): UnitSummary
    {
        $unit = $this->units->lock($unitId)
            ?? throw ValidationException::withMessages(['unitId' => __('Choose one of your units.')]);

        if (! $this->access->allows($by, $unit->propertyId)) {
            // As for another organization's unit: an assigned agent's other buildings answer 404.
            Response::denyAsNotFound()->authorize();
        }

        if ($unit->propertyArchived) {
            throw ValidationException::withMessages(['unitId' => __(':building is archived. Restore it before letting its units.', ['building' => $unit->propertyName])]);
        }

        return $unit;
    }

    /**
     * The status the unit must have after this change, from every live lease
     * of the unit (inside the transaction, its row locked): occupied while a
     * lease is active, reserved while only drafts wait, vacant once none
     * holds it. Null when it stays as it is; a status people set (under
     * maintenance, unavailable) is never overwritten with vacant.
     */
    private function unitStatusAfter(UnitSummary $unit): ?string
    {
        $statuses = Lease::query()->where('unit_id', $unit->id)->whereIn('status', [LeaseStatus::Active, LeaseStatus::Draft])
            ->distinct()->pluck('status')->map(static fn (mixed $status): string => $status instanceof LeaseStatus ? $status->value : (string) $status)->all();

        $wanted = match (true) {
            in_array(LeaseStatus::Active->value, $statuses, true) => UnitDirectory::OCCUPIED,
            in_array(LeaseStatus::Draft->value, $statuses, true) => UnitDirectory::RESERVED,
            default => UnitDirectory::VACANT,
        };

        $heldByLease = $unit->status === UnitDirectory::OCCUPIED || $unit->status === UnitDirectory::RESERVED;

        if ($wanted === $unit->status || (! $heldByLease && $wanted !== UnitDirectory::OCCUPIED && $unit->status !== UnitDirectory::VACANT)) {
            return null;
        }

        return $wanted;
    }

    /**
     * The common event fields of a lease.
     *
     * @return array{organizationId: int, leaseId: int, propertyId: int, unitId: int, tenantProfileId: int, unitStatus: string|null}
     */
    private function eventIds(Lease $lease, ?string $unitStatus): array
    {
        return [
            'organizationId' => $lease->organization_id,
            'leaseId' => $lease->id,
            'propertyId' => $lease->property_id,
            'unitId' => $lease->unit_id,
            'tenantProfileId' => $lease->tenant_profile_id,
            'unitStatus' => $unitStatus,
        ];
    }

    /** @throws LeaseRefused when the state machine does not allow the move */
    private function refuseUnlessMove(Lease $lease, LeaseStatus $to, string $action): void
    {
        if (! $lease->status->canMoveTo($to)) {
            throw LeaseRefused::because(__('A :status lease cannot be :action.', ['status' => mb_strtolower(__($lease->status->label())), 'action' => $action]));
        }
    }

    private function lockLease(int $leaseId): Lease
    {
        return Lease::query()->whereKey($leaseId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Lease $lease, ?User $by, string $description, array $properties = []): void
    {
        $entry = activity(self::LOG)
            ->event($event)
            ->performedOn($lease)
            ->withProperties(['contract_number' => $lease->contract_number, 'unit_id' => $lease->unit_id, 'tenant_profile_id' => $lease->tenant_profile_id, ...$properties]);

        if ($by !== null) {
            $entry->causedBy($by);
        }

        $entry->log($description);
    }
}
