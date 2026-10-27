<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Models\BankStatementLine;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * Finds the bill a statement credit pays (ONBOARDING_COMPLIANCE.md § 4.1,
 * P03-07b), reading only: Matching\LineMatches records what it finds.
 *
 * 1. **High confidence:** a payment reference in the narration
 *    (NarrationReferences) is a bill of this organization (Billing's
 *    BillLedger::findByReference). References of one tenant's bills → that
 *    tenant, the first referenced bill that is not void (none: the tenant
 *    alone, their open bills oldest first). References of two tenants'
 *    bills → nothing (an owner or administrator decides).
 * 2. **Suggested (medium):** no reference found, and the credit is exactly
 *    what open bills still owe (BillLedger::openBillsOwing) for exactly one
 *    tenant whose name is like the payer's (NameSimilarity; archived
 *    tenants left out) → that tenant's oldest such bill. Two or more
 *    tenants → nothing.
 * 3. Otherwise nothing: the credit waits for matching by hand.
 *
 * Only the current organization's bills and tenants are read
 * (OrganizationContext), so another organization's reference in a
 * narration finds nothing.
 */
final readonly class MatchFinder
{
    public function __construct(
        private BillLedger $ledger,
        private TenantDirectory $tenants,
    ) {}

    public function find(BankStatementLine $line): ?MatchSuggestion
    {
        if (! $line->isOfferedForMatching()) {
            return null;
        }

        $referenced = $this->referencedBills($line->narration);

        if ($referenced !== []) {
            return $this->byReference($referenced);
        }

        return $this->byAmountAndName($line);
    }

    /**
     * @param  list<BillBalance>  $bills
     */
    private function byReference(array $bills): ?MatchSuggestion
    {
        $tenantIds = array_values(array_unique(array_map(static fn (BillBalance $bill): int => $bill->tenantProfileId, $bills)));

        if (count($tenantIds) !== 1) {
            return null;
        }

        $payable = array_values(array_filter($bills, static fn (BillBalance $bill): bool => ! $bill->isVoid()));

        return new MatchSuggestion(MatchConfidence::High, $tenantIds[0], $payable[0]->id ?? null);
    }

    private function byAmountAndName(BankStatementLine $line): ?MatchSuggestion
    {
        $bills = $this->ledger->openBillsOwing($line->amount_kobo);

        if ($bills === []) {
            return null;
        }

        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (BillBalance $bill): int => $bill->tenantProfileId, $bills))));
        $payer = trim(($line->counterparty_name ?? '').' '.$line->narration);
        $similar = [];

        foreach ($bills as $bill) {
            $tenant = $tenants[$bill->tenantProfileId] ?? null;

            if ($tenant !== null && ! $tenant->archived && ! isset($similar[$tenant->id]) && NameSimilarity::similar($tenant->name, $payer)) {
                // Bills come oldest due first: the tenant's first is their oldest of that amount.
                $similar[$tenant->id] = $bill;
            }
        }

        if (count($similar) !== 1) {
            return null;
        }

        $bill = array_values($similar)[0];

        return new MatchSuggestion(MatchConfidence::Medium, $bill->tenantProfileId, $bill->id);
    }

    /** @return list<BillBalance> the organization's bills referenced in the narration, in narration order */
    private function referencedBills(string $narration): array
    {
        $bills = [];

        foreach (NarrationReferences::in($narration) as $candidate) {
            $bill = $this->ledger->findByReference($candidate);

            if ($bill !== null) {
                $bills[$bill->id] = $bill;
            }
        }

        return array_values($bills);
    }
}
