<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Media\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Billing\Contracts\OwnerBill;
use Modules\Billing\Contracts\OwnerBills;
use Modules\Leasing\Contracts\LeaseHistory;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\OwnerLeaseContracts;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Payments\Contracts\OwnerPayment;
use Modules\Payments\Contracts\OwnerPayments;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Contracts\OwnerTenants;
use Modules\Tenants\Contracts\TenantDetails;

/**
 * What the owner portal's pages show (P03-15, P03-16), read only through
 * OwnerPortfolios (the "linked to my user" path, ARCHITECTURE.md § 4):
 * every building of the user's owner profiles in each organization that
 * manages some (archived buildings too, marked: the agency no longer
 * manages them), their units and occupancy, and, read-only, everything the
 * agency holds about who lives there and how they are billed (product
 * owner 2026-10-29, docs/plans/owner-visibility-2026-10-29.md):
 *
 * - the tenants of the owner's buildings in full (Tenants' OwnerTenants:
 *   name, phone, email, address, occupation, emergency contact, KYC
 *   status, ID type; the ID number masked to its last four characters,
 *   never more), for a tenant who holds or held an activated lease there;
 * - each unit's lease history and each lease's moves, deposit ledger and
 *   issued contract (Leasing's OwnerLeases, OwnerLeaseContracts);
 * - the bills (Billing's OwnerBills: figures, lines, the tenant as issued;
 *   never the payment reference) and what was paid on them, with the
 *   receipts as on-screen pages (Payments' OwnerPayments: no reference, no
 *   bank details, never the tenants' proofs).
 *
 * Records stay per organization: each agency's card shows the leases,
 * bills and payments it holds (history never moves on a handover). A
 * record page finds its record among the user's own buildings, so anyone
 * else's answers null (the page: 404). Reads only: nothing here writes.
 */
final readonly class OwnerPortalRecords
{
    /** Bills listed on a building or lease page; the count says how many there are. */
    public const int BILLS_SHOWN = 50;

    /** Payments listed on a lease page; the count says how many there are. */
    public const int PAYMENTS_SHOWN = 50;

    public function __construct(
        private OwnerPortfolios $portfolios,
        private OwnerLeases $leases,
        private OwnerLeaseContracts $contracts,
        private OwnerBills $bills,
        private OwnerPayments $payments,
        private OwnerTenants $tenants,
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
            $tenants = $this->tenantsOf($leases);
            $byUnit = [];

            foreach ($leases as $lease) {
                $byUnit[$lease->unitId] ??= $lease;
            }

            return new OwnerBuildingPage(
                agency: OwnerAgency::of($portfolio->organization),
                building: $this->building($building),
                units: array_values($units->map(static fn (Unit $unit): OwnerUnit => self::unit(
                    $unit,
                    isset($byUnit[$unit->id]) ? self::lease($byUnit[$unit->id], $tenants[$byUnit[$unit->id]->tenantProfileId] ?? null, $unit->unit_label, $building->name) : null,
                ))->all()),
                bills: $this->bills->forBuildings([$building->id], limit: self::BILLS_SHOWN),
                billCount: $this->bills->countFor([$building->id]),
            );
        });
    }

    /** One of the owner's units with every lease activated on it, newest first (P03-16). */
    public function unitPage(User $user, int $unitId): ?OwnerUnitPage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($unitId): ?OwnerUnitPage {
            $buildingIds = self::buildingIdsOf($portfolio);
            $unit = $buildingIds === [] ? null : Unit::query()->withTrashed()->whereKey($unitId)->whereIn('property_id', $buildingIds)->first();

            if (! $unit instanceof Unit) {
                return null;
            }

            $building = self::buildingsOf($portfolio)->whereKey($unit->property_id)->firstOrFail();
            $histories = $this->leases->forUnit($unit->id, [$building->id]);
            $tenants = $this->tenantsOf(array_map(static fn (LeaseHistory $history): LeaseSummary => $history->lease, $histories));

            return new OwnerUnitPage(
                agency: OwnerAgency::of($portfolio->organization),
                buildingId: $building->id,
                building: $building->name,
                unit: self::unit($unit, null),
                leases: array_map(static fn (LeaseHistory $history): OwnerUnitLease => new OwnerUnitLease(
                    $history,
                    isset($tenants[$history->lease->tenantProfileId]) ? $tenants[$history->lease->tenantProfileId]->name : '',
                ), $histories),
            );
        });
    }

    /** A tenant who holds or held an activated lease in one of the owner's buildings, in full (ID number masked) (P03-16). */
    public function tenantPage(User $user, int $tenantId): ?OwnerTenantPage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($tenantId): ?OwnerTenantPage {
            $leases = $this->leases->forTenant($tenantId, self::buildingIdsOf($portfolio));
            $tenant = $leases === [] ? null : ($this->tenants->details([$tenantId])[$tenantId] ?? null);

            if (! $tenant instanceof TenantDetails) {
                return null;
            }

            [$units, $buildings] = self::placesOf($leases);

            return new OwnerTenantPage(
                agency: OwnerAgency::of($portfolio->organization),
                tenant: $tenant,
                leases: array_map(static fn (LeaseSummary $lease): OwnerLease => self::lease($lease, $tenant, $units[$lease->unitId] ?? '', $buildings[$lease->propertyId] ?? ''), $leases),
            );
        });
    }

    public function leasePage(User $user, int $leaseId): ?OwnerLeasePage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($leaseId): ?OwnerLeasePage {
            $history = $this->leases->history($leaseId, self::buildingIdsOf($portfolio));

            if ($history === null) {
                return null;
            }

            $lease = $history->lease;
            $paid = $this->payments->forBills($this->bills->idsFor([$lease->propertyId], $lease->id));
            $shown = array_slice($paid, 0, self::PAYMENTS_SHOWN);

            return new OwnerLeasePage(
                agency: OwnerAgency::of($portfolio->organization),
                lease: $this->ownerLease($lease),
                history: $history,
                bills: $this->bills->forBuildings([$lease->propertyId], $lease->id, self::BILLS_SHOWN),
                billCount: $this->bills->countFor([$lease->propertyId], $lease->id),
                payments: $shown,
                paymentCount: count($paid),
                billNumbers: $this->billNumbers($shown),
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
                payments: $this->payments->forBills([$bill->id]),
                tenantId: $lease?->tenantProfileId,
            );
        });
    }

    /** A receipt that settled one of the owner's bills, with only its part for them (P03-16). */
    public function receiptPage(User $user, int $receiptId): ?OwnerReceiptPage
    {
        return $this->portfolios->first($user, function (OwnerPortfolio $portfolio) use ($receiptId): ?OwnerReceiptPage {
            $buildingIds = array_flip(self::buildingIdsOf($portfolio));
            $owned = array_values(array_filter(
                $this->bills->findMany($this->payments->billIdsOfReceipt($receiptId)),
                static fn (OwnerBill $bill): bool => $bill->propertyId !== null && isset($buildingIds[$bill->propertyId]),
            ));
            $receipt = $owned === [] ? null : $this->payments->receipt($receiptId, array_map(static fn (OwnerBill $bill): int => $bill->id, $owned));

            if ($receipt === null) {
                return null;
            }

            $building = self::buildingsOf($portfolio)->whereKey((int) $owned[0]->propertyId)->firstOrFail();

            return new OwnerReceiptPage(
                agency: OwnerAgency::of($portfolio->organization),
                receipt: $receipt,
                tenantName: $owned[0]->tenantName,
                buildingId: $building->id,
                building: $building->name,
            );
        });
    }

    /** The issued contract of a lease of the owner's units, as kept (never issued from here), or null (P03-16). */
    public function contractPdf(User $user, int $leaseId): ?Media
    {
        return $this->portfolios->first($user, fn (OwnerPortfolio $portfolio): ?Media => $this->contracts->pdf($leaseId, self::buildingIdsOf($portfolio)));
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

    private static function unit(Unit $unit, ?OwnerLease $lease): OwnerUnit
    {
        return new OwnerUnit(
            id: $unit->id,
            label: $unit->unit_label,
            typeLabel: $unit->unit_type->label(),
            status: $unit->status->value,
            statusLabel: $unit->status->label(),
            statusColor: $unit->status->color(),
            lease: $lease,
        );
    }

    /** The lease page's lease: the unit, the building and the tenant's name, phone and email. */
    private function ownerLease(LeaseSummary $lease): OwnerLease
    {
        [$units, $buildings] = self::placesOf([$lease]);

        return self::lease($lease, $this->tenants->details([$lease->tenantProfileId])[$lease->tenantProfileId] ?? null, $units[$lease->unitId] ?? '', $buildings[$lease->propertyId] ?? '');
    }

    private static function lease(LeaseSummary $lease, ?TenantDetails $tenant, string $unit, string $building): OwnerLease
    {
        return new OwnerLease(
            lease: $lease,
            tenantName: $tenant !== null ? $tenant->name : '',
            unit: $unit,
            building: $building,
            tenantPhone: $tenant?->phone,
            tenantEmail: $tenant?->email,
        );
    }

    /**
     * The leases' tenants, by id (ID numbers masked by the Tenants module).
     *
     * @param  list<LeaseSummary>  $leases
     * @return array<int, TenantDetails>
     */
    private function tenantsOf(array $leases): array
    {
        return $this->tenants->details(array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->tenantProfileId, $leases))));
    }

    /**
     * The leases' unit labels and building names (archived ones too), by id.
     *
     * @param  list<LeaseSummary>  $leases
     * @return array{array<int, string>, array<int, string>}
     */
    private static function placesOf(array $leases): array
    {
        $unitIds = array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->unitId, $leases)));
        $buildingIds = array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->propertyId, $leases)));

        return [
            $unitIds === [] ? [] : array_map('strval', Unit::query()->withTrashed()->whereKey($unitIds)->pluck('unit_label', 'id')->all()),
            $buildingIds === [] ? [] : array_map('strval', Property::query()->withTrashed()->whereKey($buildingIds)->pluck('name', 'id')->all()),
        ];
    }

    /**
     * The numbers of the bills these payments were for, by id.
     *
     * @param  list<OwnerPayment>  $payments
     * @return array<int, string>
     */
    private function billNumbers(array $payments): array
    {
        $ids = array_values(array_unique(array_map(static fn (OwnerPayment $payment): int => $payment->billId, $payments)));

        return $ids === [] ? [] : array_map(static fn (OwnerBill $bill): string => $bill->number, $this->bills->findMany($ids));
    }
}
