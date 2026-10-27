<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Support\Money;
use Modules\Billing\Contracts\TenantBill;

/**
 * Everything the tenant portal shows about one agency the user rents from
 * (P03-11a; Portal\PortalRentals): its name and colour now (the frame of
 * the agency's section), the user's leases there and their bills, each
 * bill with the branding it was issued with. Plain values, read inside
 * the agency's organization and safe to render outside it.
 */
final readonly class AgencyRentals
{
    /**
     * @param  list<PortalLease>  $leases  newest start first
     * @param  list<TenantBill>  $bills  latest due date first
     */
    public function __construct(
        public PortalTenancy $tenancy,
        public string $name,
        public string $colour,
        public string $initials,
        public array $leases,
        public array $bills,
    ) {}

    public function organizationId(): int
    {
        return $this->tenancy->organization->id;
    }

    public function readOnly(): bool
    {
        return $this->tenancy->readOnly();
    }

    /**
     * The bills that still ask for money, the one due first first.
     *
     * @return list<TenantBill>
     */
    public function toPay(): array
    {
        $open = array_values(array_filter($this->bills, static fn (TenantBill $bill): bool => $bill->asksForMoney()));
        usort($open, static fn (TenantBill $a, TenantBill $b): int => [$a->dueDate->toDateString(), $a->id] <=> [$b->dueDate->toDateString(), $b->id]);

        return $open;
    }

    /**
     * Paid and void bills, latest due date first.
     *
     * @return list<TenantBill>
     */
    public function settled(): array
    {
        return array_values(array_filter($this->bills, static fn (TenantBill $bill): bool => ! $bill->asksForMoney()));
    }

    /** What the user still owes this agency on open bills. */
    public function amountDue(): Money
    {
        return Money::sum(array_map(static fn (TenantBill $bill): Money => $bill->outstanding, $this->toPay()));
    }

    /** The newest bill's branding as issued, for the agency's logo above its bills (null before the first bill). */
    public function latestBill(): ?TenantBill
    {
        return $this->bills[0] ?? null;
    }
}
