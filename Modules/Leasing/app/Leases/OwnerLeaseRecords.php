<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\EndingLease;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;

/**
 * The Leasing module's answers to Contracts\OwnerLeases (the owner portal,
 * P03-15), read through the organization scope and only among the given
 * buildings' leases, so a guessed id of any other lease is not found.
 * Drafts are never shown. Reads only.
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
        if ($propertyIds === []) {
            return null;
        }

        $lease = Lease::query()->whereKey($leaseId)->whereIn('property_id', $propertyIds)
            ->where('status', '!=', LeaseStatus::Draft)
            ->first();

        return $lease instanceof Lease ? self::summary($lease) : null;
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
