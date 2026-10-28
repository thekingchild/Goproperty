<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\OwnerBills;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\OwnerStatements\Contracts\TenantContactSharing;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * What the owner portal's pages show (P03-15), read only through
 * OwnerPortfolios (the "linked to my user" path, ARCHITECTURE.md § 4):
 * every building of the user's owner profiles in each organization that
 * manages some (archived buildings too, marked: the agency no longer
 * manages them), their units and occupancy, the active leases (the
 * tenant's name only; their phone and email too when the agency shares
 * tenants' contact details with the owner, P08-05), and the buildings'
 * bills with their status. A
 * building, lease or bill page finds its record among the user's own
 * buildings, so anyone else's answers null (the page: 404). Reads only:
 * nothing here writes.
 */
final readonly class OwnerPortalRecords
{
    /** Bills listed on a building or lease page; the count says how many there are. */
    public const int BILLS_SHOWN = 50;

    public function __construct(
        private OwnerPortfolios $portfolios,
        private OwnerLeases $leases,
        private OwnerBills $bills,
        private TenantDirectory $tenants,
        private TenantContactSharing $sharing,
    ) {}

    /**
     * The portal's home: per organization, the owner's buildings.
     *
     * @return list<AgencyBuildings>
     */
    public function home(User $user): array
    {
        return $this->portfolios->map($user, fn (OwnerPortfolio $portfolio): AgencyBuildings => new AgencyBuildings(
            OwnerAgency::of($portfolio->organization),
            array_values(array_map(fn (Property $building): OwnerBuilding => $this->building($building), self::buildingsOf($portfolio)
                ->withCount('units')
                ->orderBy('status')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->all())),
        ));
    }

    public function buildingPage(User $user, int $buildingId): ?OwnerBuildingPage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($buildingId): ?OwnerBuildingPage {
            $building = self::buildingsOf($portfolio)->whereKey($buildingId)->withCount('units')->first();

            if (! $building instanceof Property) {
                return null;
            }

            $units = Unit::query()->where('property_id', $building->id)->orderBy('unit_label')->orderBy('id')->get();
            $leases = $this->leases->activeIn([$building->id]);
            $names = $this->tenantNames($leases);
            $byUnit = [];

            foreach ($leases as $lease) {
                $byUnit[$lease->unitId] ??= $lease;
            }

            return new OwnerBuildingPage(
                agency: OwnerAgency::of($portfolio->organization),
                building: $this->building($building),
                units: array_values($units->map(static fn (Unit $unit): OwnerUnit => new OwnerUnit(
                    id: $unit->id,
                    label: $unit->unit_label,
                    typeLabel: $unit->unit_type->label(),
                    status: $unit->status->value,
                    statusLabel: $unit->status->label(),
                    statusColor: $unit->status->color(),
                    lease: isset($byUnit[$unit->id])
                        ? new OwnerLease($byUnit[$unit->id], $names[$byUnit[$unit->id]->tenantProfileId] ?? '', $unit->unit_label, $building->name)
                        : null,
                ))->all()),
                bills: $this->bills->forBuildings([$building->id], limit: self::BILLS_SHOWN),
                billCount: $this->bills->countFor([$building->id]),
            );
        });
    }

    public function leasePage(User $user, int $leaseId): ?OwnerLeasePage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($leaseId): ?OwnerLeasePage {
            $buildingIds = self::buildingIdsOf($portfolio);
            $lease = $this->leases->find($leaseId, $buildingIds);

            if ($lease === null) {
                return null;
            }

            return new OwnerLeasePage(
                agency: OwnerAgency::of($portfolio->organization),
                lease: $this->ownerLease($lease),
                bills: $this->bills->forBuildings([$lease->propertyId], $lease->id, self::BILLS_SHOWN),
                billCount: $this->bills->countFor([$lease->propertyId], $lease->id),
            );
        });
    }

    public function billPage(User $user, int $billId): ?OwnerBillPage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($billId): ?OwnerBillPage {
            $buildingIds = self::buildingIdsOf($portfolio);
            $bill = $this->bills->find($billId, $buildingIds);

            if ($bill === null || $bill->propertyId === null) {
                return null;
            }

            $building = self::buildingsOf($portfolio)->whereKey($bill->propertyId)->firstOrFail();
            $lease = $bill->leaseId !== null ? $this->leases->find($bill->leaseId, [$building->id]) : null;

            return new OwnerBillPage(
                agency: OwnerAgency::of($portfolio->organization),
                bill: $bill,
                buildingId: $building->id,
                building: $building->name,
                leaseId: $lease?->id,
            );
        });
    }

    /**
     * The buildings of the portfolio's owner profiles (the scope keeps them
     * in the portfolio's organization; import-rolled-back ones are gone).
     *
     * @return Builder<Property>
     */
    private static function buildingsOf(OwnerPortfolio $portfolio): Builder
    {
        return Property::query()->whereIn('owner_profile_id', $portfolio->ownerProfileIds);
    }

    /** @return list<int> */
    private static function buildingIdsOf(OwnerPortfolio $portfolio): array
    {
        return array_values(array_map('intval', self::buildingsOf($portfolio)->orderBy('id')->pluck('id')->all()));
    }

    private function building(Property $building): OwnerBuilding
    {
        $byStatus = [];

        foreach (Unit::query()->where('property_id', $building->id)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $status = UnitStatus::tryFrom((string) $row->status);

            if ($status !== null) {
                $byStatus[$status->value] = (int) $row->total;
            }
        }

        return new OwnerBuilding(
            id: $building->id,
            name: $building->name,
            address: $building->address,
            typeLabel: $building->property_type->label(),
            archived: $building->isArchived(),
            unitCount: (int) ($building->units_count ?? array_sum($byStatus)),
            unitsByStatus: $byStatus,
        );
    }

    /**
     * The lease page's lease: the tenant's name, and their phone and email
     * only when the agency shares tenants' contact details with the
     * building's owner (P08-05, Modules\OwnerStatements' switch).
     */
    private function ownerLease(LeaseSummary $lease): OwnerLease
    {
        $unit = Unit::query()->withTrashed()->whereKey($lease->unitId)->first();
        $building = Property::query()->withTrashed()->whereKey($lease->propertyId)->first();
        $tenant = $this->tenants->findMany([$lease->tenantProfileId])[$lease->tenantProfileId] ?? null;
        $shares = $building instanceof Property && $building->owner_profile_id !== null && $this->sharing->sharesWith($building->owner_profile_id);

        return new OwnerLease(
            lease: $lease,
            tenantName: $tenant !== null ? $tenant->name : '',
            unit: $unit instanceof Unit ? $unit->unit_label : '',
            building: $building instanceof Property ? $building->name : '',
            tenantPhone: $shares ? $tenant?->phone : null,
            tenantEmail: $shares ? $tenant?->email : null,
        );
    }

    /**
     * The tenants' names, by tenant id: only the name (TenantDirectory).
     *
     * @param  list<LeaseSummary>  $leases
     * @return array<int, string>
     */
    private function tenantNames(array $leases): array
    {
        $ids = array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->tenantProfileId, $leases)));

        return array_map(static fn ($tenant): string => $tenant->name, $this->tenants->findMany($ids));
    }
}
