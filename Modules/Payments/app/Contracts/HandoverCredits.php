<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use App\Models\User;
use App\Support\Money;

/**
 * Tenant credit at a management handover (P03 decision 11,
 * BILLING_PAYMENTS.md § 7.4): credit is money a tenant paid into the
 * landlord's account, so it never moves by itself. The handover lists each
 * moving tenant's credit; the landlord either passes it to the agency
 * outside GoProperty (the agency then records it as the tenant's opening
 * credit, once it confirms the money arrived) or refunds the tenant
 * (recorded as a refund that closes the credit). Until then it stays on
 * the landlord's books.
 *
 * For the Handovers module: never the Payments module's tables
 * (ARCHITECTURE.md § 8 rule 3). Answers and writes in the current
 * organization only; writes run inside the caller's transaction, on the
 * tenant's locked credit balance, with an entry each (entries are never
 * deleted) and an audit entry. No permission check: the caller checks its
 * policy. Bound in PaymentsServiceProvider.
 */
interface HandoverCredits
{
    /**
     * The credit each of these tenants holds now (tenants without any are
     * left out).
     *
     * @param  list<int>  $tenantProfileIds
     * @return array<int, Money> by tenant profile id
     */
    public function balances(array $tenantProfileIds): array;

    /**
     * Closes all of the tenant's credit, which must be exactly $amount (the
     * amount the person acting was shown): refunded to the tenant, or
     * passed to the organization now managing the building.
     *
     * @throws CreditChanged when the credit is not $amount any more
     */
    public function close(int $tenantProfileId, Money $amount, bool $refunded, ?User $by, string $note): void;

    /** Records credit the previous manager passed on, as the tenant's opening credit here. */
    public function open(int $tenantProfileId, Money $amount, ?User $by, string $note): void;
}
