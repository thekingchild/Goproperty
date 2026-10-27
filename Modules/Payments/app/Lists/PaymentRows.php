<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentProof;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The tenants' names of a batch of payments, read through the Tenants
 * contract with one lookup per batch (no query per payment,
 * ARCHITECTURE.md § 13), never by joining its table (§ 8 rule 3); and for
 * payments from proofs (P03-09), their proofs and whether the same file
 * came with another proof: two more queries per batch.
 */
final readonly class PaymentRows
{
    public function __construct(private TenantDirectory $tenants) {}

    /**
     * @param  iterable<Payment>  $payments
     * @return list<PaymentRow>
     */
    public function rows(iterable $payments): array
    {
        $payments = is_array($payments) ? array_values($payments) : iterator_to_array($payments, false);

        if ($payments === []) {
            return [];
        }

        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (Payment $payment): int => $payment->tenant_profile_id, $payments))));
        [$proofs, $repeated] = $this->proofs($payments);

        return array_map(static fn (Payment $payment): PaymentRow => new PaymentRow(
            payment: $payment,
            tenantName: isset($tenants[$payment->tenant_profile_id]) ? $tenants[$payment->tenant_profile_id]->name : (string) __('Unknown tenant'),
            hasProof: isset($proofs[$payment->id]),
            sameFileAsAnotherProof: isset($proofs[$payment->id]) && isset($repeated[$proofs[$payment->id]]),
        ), $payments);
    }

    /**
     * The file hash of each payment's proof (payment id => sha256), and the
     * hashes the organization has more than one proof of.
     *
     * @param  list<Payment>  $payments
     * @return array{array<int, string>, array<string, true>}
     */
    private function proofs(array $payments): array
    {
        $ids = array_map(static fn (Payment $payment): int => $payment->id, array_values(array_filter($payments, static fn (Payment $payment): bool => $payment->source === PaymentSource::ProofUpload)));

        if ($ids === []) {
            return [[], []];
        }

        $proofs = [];

        foreach (PaymentProof::query()->whereIn('payment_id', $ids)->get(['payment_id', 'file_sha256']) as $proof) {
            $proofs[$proof->payment_id] = $proof->file_sha256;
        }

        $repeated = [];

        if ($proofs !== []) {
            $hashes = PaymentProof::query()->whereIn('file_sha256', array_values(array_unique($proofs)))
                ->groupBy('file_sha256')->havingRaw('count(*) > 1')->pluck('file_sha256');

            foreach ($hashes as $hash) {
                $repeated[(string) $hash] = true;
            }
        }

        return [$proofs, $repeated];
    }
}
