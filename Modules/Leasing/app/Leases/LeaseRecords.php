<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Leasing\Contracts\BillableLease;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Contracts\LeaseBillingSchedule;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Models\Lease;

/**
 * The Leasing module's answers to Contracts\TenantLeases (for the Tenants
 * module) and Contracts\LeaseBillingSchedule (for Billing), read through
 * the organization scope. The one write, advance(), goes through
 * LeaseService.
 */
final readonly class LeaseRecords implements LeaseBillingSchedule, TenantLeases
{
    public function __construct(private CycleCalculator $calculator) {}

    public function buildingsOf(int $tenantProfileId): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            Lease::query()->where('tenant_profile_id', $tenantProfileId)->distinct()->orderBy('property_id')->pluck('property_id')->all(),
        ));
    }

    public function tenantIdsQuery(?array $propertyIds = null): QueryBuilder
    {
        return Lease::query()->select('tenant_profile_id')
            ->when($propertyIds !== null, fn (Builder $query) => $query->whereIn('property_id', $propertyIds ?? []))
            ->toBase();
    }

    public function holdsUnit(int $tenantProfileId): bool
    {
        return Lease::query()->where('tenant_profile_id', $tenantProfileId)
            ->whereIn('status', [LeaseStatus::Draft, LeaseStatus::Active])->exists();
    }

    public function forTenant(int $tenantProfileId): array
    {
        return array_values(Lease::query()->where('tenant_profile_id', $tenantProfileId)
            ->orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(static fn (Lease $lease): LeaseSummary => new LeaseSummary(
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
            ))
            ->all());
    }

    public function due(DateTimeInterface $date, int $leadDays = CycleCalculator::DEFAULT_LEAD_DAYS, int $afterId = 0, int $limit = 500): array
    {
        $cutoff = $this->calculator->daysAfter($date, max(0, $leadDays))->toDateString();

        return array_values(self::billable()
            ->where('next_billing_date', '<=', $cutoff)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(self::billableLease(...))
            ->all());
    }

    public function find(int $leaseId): ?BillableLease
    {
        $lease = self::billable()->find($leaseId);

        return $lease instanceof Lease ? self::billableLease($lease) : null;
    }

    public function advance(int $leaseId, DateTimeInterface $periodStart): ?CarbonImmutable
    {
        return app(LeaseService::class)->advanceBilling($leaseId, $periodStart);
    }

    /**
     * Active and renewed leases with a period left to bill (its start on or
     * before the end date).
     *
     * @return Builder<Lease>
     */
    private static function billable(): Builder
    {
        return Lease::query()
            ->whereIn('status', [LeaseStatus::Active, LeaseStatus::Renewed])
            ->whereNotNull('next_billing_date')
            ->whereColumn('next_billing_date', '<=', 'end_date');
    }

    private static function billableLease(Lease $lease): BillableLease
    {
        return new BillableLease(
            id: $lease->id,
            organizationId: $lease->organization_id,
            propertyId: $lease->property_id,
            unitId: $lease->unit_id,
            tenantProfileId: $lease->tenant_profile_id,
            contractNumber: (string) $lease->contract_number,
            status: $lease->status->value,
            cycle: $lease->billing_cycle,
            startDate: $lease->start_date,
            endDate: $lease->end_date,
            nextBillingDate: $lease->next_billing_date ?? $lease->start_date,
            rent: $lease->rent_amount_kobo,
            dueDayOffset: $lease->due_day_offset,
        );
    }
}
