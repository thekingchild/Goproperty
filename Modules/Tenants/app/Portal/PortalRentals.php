<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Contracts\TenantBill;
use Modules\Billing\Contracts\TenantBillPayment;
use Modules\Billing\Contracts\TenantBills;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\TenantProof;
use Modules\Payments\Contracts\TenantProofs;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * What the tenant portal shows (P03-11a; UX_DESIGN.md § 2: "each agency's
 * bills shown with that agency's logo and colours"): per agency the user
 * rents from, their leases with the homes they are for, and their bills.
 * Read only through Portal\PortalTenancies (the "linked to my user" path)
 * and inside each agency's organization, through the Leasing, Billing and
 * Properties contracts (ARCHITECTURE.md § 8 rule 3), limited to the
 * user's own tenant profiles there.
 *
 * Draft leases are the agency's work in progress and are not shown; a
 * lease's page lists its bills.
 */
final readonly class PortalRentals
{
    public function __construct(
        private PortalTenancies $tenancies,
        private TenantLeases $leases,
        private TenantBills $bills,
        private UnitDirectory $units,
        private PropertyDirectory $properties,
        private PortalChecks $checks,
        private TenantProofs $proofs,
        private PaymentProofs $paymentProofs,
    ) {}

    /** @return list<AgencyRentals> by agency name */
    public function of(User $user): array
    {
        return $this->tenancies->map($user, fn (PortalTenancy $tenancy): AgencyRentals => $this->agency($tenancy));
    }

    /**
     * One of the user's bills with how to pay it and its agency, or null
     * (another tenant's or organization's bill, or none: the page answers
     * 404). Checked against TenantProfilePolicy::viewAsTenant for the
     * bill's tenant.
     */
    public function billPage(User $user, int $billId): ?PortalBillPage
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $billId): ?PortalBillPage {
            $payment = $this->bills->payment($billId, $tenancy->tenantProfileIds);

            if (! $payment instanceof TenantBillPayment) {
                return null;
            }

            $profile = $this->checks->authorize($user, 'viewAsTenant', $payment->bill->tenantProfileId);

            return new PortalBillPage(
                agency: $this->frame($tenancy),
                payment: $payment,
                invoice: Gate::forUser($user)->allows('viewInvoiceAsTenant', $profile),
                proofs: array_values(array_filter(
                    $this->proofs->forTenants($tenancy->tenantProfileIds),
                    static fn (TenantProof $proof): bool => $proof->billId === $billId,
                )),
                mayUploadProof: $payment->bill->asksForMoney() && $this->paymentProofs->maySubmit($user, $billId),
                files: Gate::forUser($user)->allows('downloadAsTenant', $profile),
            );
        });

        return $found[1] ?? null;
    }

    /**
     * One of the user's bills' invoice as a web page, or null (404).
     * Checked against TenantProfilePolicy::viewInvoiceAsTenant (not while
     * staff impersonate: it prints the tenant's contact details).
     */
    public function invoice(User $user, int $billId): ?string
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $billId): ?string {
            $bill = $this->bills->find($billId, $tenancy->tenantProfileIds);

            if (! $bill instanceof TenantBill) {
                return null;
            }

            $this->checks->authorize($user, 'viewInvoiceAsTenant', $bill->tenantProfileId);

            return $this->bills->invoicePage($billId, $tenancy->tenantProfileIds);
        });

        return $found[1] ?? null;
    }

    /**
     * One of the user's leases (not a draft) with its agency and its bills, or null.
     *
     * @return array{AgencyRentals, PortalLease}|null
     */
    public function lease(User $user, int $leaseId): ?array
    {
        $found = $this->tenancies->first($user, function (PortalTenancy $tenancy) use ($user, $leaseId): ?array {
            $leases = array_values(array_filter($this->leasesOf($tenancy), static fn (PortalLease $lease): bool => $lease->lease->id === $leaseId));

            if ($leases === []) {
                return null;
            }

            $this->checks->authorize($user, 'viewAsTenant', $leases[0]->lease->tenantProfileId);
            $bills = array_values(array_filter(
                $this->bills->forTenants($tenancy->tenantProfileIds),
                static fn (TenantBill $bill): bool => $bill->leaseId === $leaseId,
            ));

            return [$this->frame($tenancy, $leases, $bills), $leases[0]];
        });

        return $found[1] ?? null;
    }

    /** Inside the tenancy's organization. */
    private function agency(PortalTenancy $tenancy): AgencyRentals
    {
        return $this->frame($tenancy, $this->leasesOf($tenancy), $this->bills->forTenants($tenancy->tenantProfileIds));
    }

    /**
     * @param  list<PortalLease>  $leases
     * @param  list<TenantBill>  $bills
     */
    private function frame(PortalTenancy $tenancy, array $leases = [], array $bills = []): AgencyRentals
    {
        return AgencyRentals::of($tenancy, $leases, $bills);
    }

    /**
     * The tenancy's leases, drafts left out, newest start first. Inside its organization.
     *
     * @return list<PortalLease>
     */
    public function leasesOf(PortalTenancy $tenancy): array
    {
        $summaries = [];

        foreach ($tenancy->tenantProfileIds as $tenantProfileId) {
            foreach ($this->leases->forTenant($tenantProfileId) as $lease) {
                if ($lease->status !== 'draft') {
                    $summaries[] = $lease;
                }
            }
        }

        usort($summaries, static fn (LeaseSummary $a, LeaseSummary $b): int => [$b->startDate->toDateString(), $b->id] <=> [$a->startDate->toDateString(), $a->id]);

        $units = $this->units->findMany(array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->unitId, $summaries))));
        $buildings = $this->properties->findMany(array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->propertyId, $summaries))));

        return array_map(static function (LeaseSummary $lease) use ($units, $buildings): PortalLease {
            $unit = $units[$lease->unitId] ?? null;
            $building = $buildings[$lease->propertyId] ?? null;

            return new PortalLease(
                lease: $lease,
                building: $building->name ?? $unit->propertyName ?? __('Your building'),
                unit: $unit->label ?? __('Your home'),
                address: $building !== null ? self::address($building->address, $building->state) : null,
            );
        }, $summaries);
    }

    /** "22 Fola Osibo Road, Lekki Phase 1, Lagos": the state added unless the address names it. */
    private static function address(string $address, string $state): ?string
    {
        $address = trim($address);
        $state = trim($state);

        if ($state !== '' && stripos($address, $state) === false) {
            $address = $address !== '' ? $address.', '.$state : $state;
        }

        return $address !== '' ? $address : null;
    }
}
