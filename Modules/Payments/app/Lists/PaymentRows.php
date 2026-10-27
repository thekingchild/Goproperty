<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use Modules\Payments\Models\Payment;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The tenants' names of a batch of payments, read through the Tenants
 * contract with one lookup per batch (no query per payment,
 * ARCHITECTURE.md § 13), never by joining its table (§ 8 rule 3).
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

        return array_map(static fn (Payment $payment): PaymentRow => new PaymentRow(
            payment: $payment,
            tenantName: isset($tenants[$payment->tenant_profile_id]) ? $tenants[$payment->tenant_profile_id]->name : (string) __('Unknown tenant'),
        ), $payments);
    }
}
