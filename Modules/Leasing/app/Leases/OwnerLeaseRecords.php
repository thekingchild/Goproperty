<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Leasing\Contracts\DepositLine;
use Modules\Leasing\Contracts\EndingLease;
use Modules\Leasing\Contracts\LeaseEvent;
use Modules\Leasing\Contracts\LeaseHistory;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Modules\Leasing\Models\LeaseDepositEntry;

/**
 * The Leasing module's answers to Contracts\OwnerLeases (the owner portal,
 * P03-15, P03-16), read through the organization scope and only among the
 * given buildings' leases, so a guessed id of any other lease is not found.
 * Only activated leases are shown: never a draft, nor a draft cancelled or
 * let lapse. A lease's history is worked out from what it records — its
 * start and end, the lease it renews (and that lease's rent), its
 * termination, the renewal that followed it — and its deposit ledger. Reads
 * only.
 */
final readonly class OwnerLeaseRecords implements OwnerLeases
{
    public function activeIn(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(Lease::query()->whereIn('property_id', $propertyIds)
            ->where('status', LeaseStatus::Active)
            ->orderBy('property_id')->orderBy('unit_id')->orderBy('id')->get()
            ->map(self::summary(...))
            ->all());
    }

    public function find(int $leaseId, array $propertyIds): ?LeaseSummary
    {
        $lease = $propertyIds === [] ? null : self::shown($propertyIds)->whereKey($leaseId)->first();

        return $lease instanceof Lease ? self::summary($lease) : null;
    }

    public function history(int $leaseId, array $propertyIds): ?LeaseHistory
    {
        $lease = $propertyIds === [] ? null : self::shown($propertyIds)->whereKey($leaseId)->first();

        return $lease instanceof Lease ? $this->histories(collect([$lease]))[0] : null;
    }

    public function forUnit(int $unitId, array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return $this->histories(self::shown($propertyIds)->where('unit_id', $unitId)
            ->orderByDesc('start_date')->orderByDesc('id')->get());
    }

    public function forTenant(int $tenantProfileId, array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return array_values(self::shown($propertyIds)->where('tenant_profile_id', $tenantProfileId)
            ->orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(self::summary(...))
            ->all());
    }

    public function endingBetween(array $propertyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($propertyIds === []) {
            return [];
        }

        $ending = Lease::query()->whereIn('property_id', $propertyIds)
            ->where('status', LeaseStatus::Active)
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('end_date', '<=', $to->toDateString())
            ->orderBy('end_date')->orderBy('property_id')->orderBy('unit_id')->orderBy('id')
            ->get();

        $renewals = $ending->isEmpty() ? collect() : Lease::query()
            ->whereIn('renewal_of_lease_id', $ending->pluck('id')->all())
            ->where('status', LeaseStatus::Draft)
            ->orderBy('id')
            ->get()
            ->keyBy('renewal_of_lease_id');

        return array_values($ending->map(static function (Lease $lease) use ($renewals): EndingLease {
            $renewal = $renewals->get($lease->id);

            return new EndingLease(
                lease: self::summary($lease),
                autoRenew: (bool) $lease->auto_renew,
                renewal: $renewal instanceof Lease ? self::summary($renewal) : null,
            );
        })->all());
    }

    /**
     * Activated leases of these buildings: a draft has no `activated_at`,
     * and neither has a draft that was cancelled (terminated) or lapsed
     * (expired) without being let.
     *
     * @param  list<int>  $propertyIds
     * @return Builder<Lease>
     */
    private static function shown(array $propertyIds): Builder
    {
        return Lease::query()->whereIn('property_id', $propertyIds)
            ->where('status', '!=', LeaseStatus::Draft)
            ->whereNotNull('activated_at');
    }

    /**
     * Each lease with its history, in the order given; the leases they
     * renew, the renewals that followed, the contracts and the deposit
     * entries read once for all of them.
     *
     * @param  Collection<int, Lease>  $leases
     * @return list<LeaseHistory>
     */
    private function histories(Collection $leases): array
    {
        if ($leases->isEmpty()) {
            return [];
        }

        $ids = $leases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $previousIds = $leases->pluck('renewal_of_lease_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $previous = $previousIds === [] ? collect() : Lease::query()->whereKey($previousIds)->whereNotNull('activated_at')->get()->keyBy('id');
        $renewedBy = Lease::query()->whereIn('renewal_of_lease_id', $ids)
            ->where('status', '!=', LeaseStatus::Draft)
            ->whereNotNull('activated_at')
            ->orderBy('id')
            ->get(['id', 'renewal_of_lease_id'])
            ->keyBy('renewal_of_lease_id');
        $contracts = array_flip(array_map('intval', LeaseContract::query()->whereIn('lease_id', $ids)->pluck('lease_id')->all()));
        $entries = LeaseDepositEntry::query()->whereIn('lease_id', $ids)->orderBy('id')->get()->groupBy('lease_id');

        return array_values($leases->map(function (Lease $lease) use ($previous, $renewedBy, $contracts, $entries): LeaseHistory {
            $before = $lease->renewal_of_lease_id !== null ? $previous->get($lease->renewal_of_lease_id) : null;
            $after = $renewedBy->get($lease->id);
            $lines = array_values(($entries->get($lease->id) ?? collect())->map(static fn (LeaseDepositEntry $entry): DepositLine => new DepositLine(
                type: $entry->type->value,
                label: $entry->type->label(),
                amount: $entry->amount_kobo,
                note: $entry->note,
                recordedAt: $entry->created_at ?? CarbonImmutable::now(),
            ))->all());

            return new LeaseHistory(
                lease: self::summary($lease),
                renewalOfLeaseId: $before instanceof Lease ? $before->id : null,
                renewedByLeaseId: $after instanceof Lease ? $after->id : null,
                events: self::events($lease, $before instanceof Lease ? $before : null, $after instanceof Lease),
                deposit: $lease->deposit_kobo,
                depositLines: $lines,
                depositBalance: Money::sum(array_map(static fn (DepositLine $line): Money => $line->amount, $lines)),
                hasContract: isset($contracts[$lease->id]),
            );
        })->all());
    }

    /**
     * The lease's moves, oldest first: moved in (or renewed, with a rent
     * change when the renewal changed it), then ended early and moved out,
     * or moved out at the end of an expired lease that no renewal followed.
     *
     * @return list<LeaseEvent>
     */
    private static function events(Lease $lease, ?Lease $previous, bool $renewed): array
    {
        $events = [];
        $cycle = $lease->billing_cycle->label();

        if ($lease->renewal_of_lease_id !== null) {
            $follows = $previous?->contract_number;
            $events[] = $follows === null
                ? new LeaseEvent(LeaseEvent::RENEWED, 'Renewed', $lease->start_date)
                : new LeaseEvent(LeaseEvent::RENEWED, 'Renewed', $lease->start_date, __('Follows lease :number', ['number' => $follows]));

            if ($previous !== null && ! $previous->rent_amount_kobo->equals($lease->rent_amount_kobo)) {
                $events[] = new LeaseEvent(LeaseEvent::RENT_CHANGED, 'Rent changed', $lease->start_date, __('From :old to :new (:cycle)', [
                    'old' => $previous->rent_amount_kobo->format(),
                    'new' => $lease->rent_amount_kobo->format(),
                    'cycle' => mb_strtolower(__($cycle)),
                ]));
            }
        } else {
            $events[] = new LeaseEvent(LeaseEvent::MOVED_IN, 'Moved in', $lease->start_date, __(':rent (:cycle)', [
                'rent' => $lease->rent_amount_kobo->format(),
                'cycle' => mb_strtolower(__($cycle)),
            ]));
        }

        if ($lease->status === LeaseStatus::Terminated) {
            $day = self::day($lease->terminated_at) ?? $lease->end_date;
            $events[] = new LeaseEvent(LeaseEvent::TERMINATED, 'Ended early', $day, $lease->termination_reason);
            $events[] = new LeaseEvent(LeaseEvent::MOVED_OUT, 'Moved out', $day);
        } elseif ($lease->status === LeaseStatus::Expired && ! $renewed) {
            $events[] = new LeaseEvent(LeaseEvent::MOVED_OUT, 'Moved out', $lease->end_date);
        }

        return $events;
    }

    /** A recorded moment as its calendar day in Lagos (midnight, app time zone, like the `date` columns). */
    private static function day(?CarbonImmutable $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::parse($moment->setTimezone(LeaseDates::TIMEZONE)->toDateString());
    }

    private static function summary(Lease $lease): LeaseSummary
    {
        return new LeaseSummary(
            id: $lease->id,
            organizationId: $lease->organization_id,
            propertyId: $lease->property_id,
            unitId: $lease->unit_id,
            tenantProfileId: $lease->tenant_profile_id,
            contractNumber: $lease->contract_number,
            status: $lease->status->value,
            statusLabel: $lease->status->label(),
            cycle: $lease->billing_cycle,
            startDate: $lease->start_date,
            endDate: $lease->end_date,
            rent: $lease->rent_amount_kobo,
        );
    }
}
