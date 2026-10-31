<?php

declare(strict_types=1);

namespace Modules\Leasing\Leases;

use App\Settings\SettingsStore;
use Modules\Leasing\Contracts\RentPaymentLimits;
use Modules\Leasing\Models\Lease;

/** See Contracts\RentPaymentLimits (P03-17). */
final readonly class RentPaymentRules implements RentPaymentLimits
{
    public function __construct(private SettingsStore $settings) {}

    public function organizationDefault(): ?int
    {
        $value = $this->settings->get(self::SETTING);

        return is_int($value) && $value >= 1 && $value <= self::MAX ? $value : null;
    }

    public function forLease(int $leaseId): ?int
    {
        // Through the model's organization scope: another organization's lease is not found.
        $lease = Lease::query()->withTrashed()->whereKey($leaseId)->first(['id', 'max_rent_payments']);

        if (! $lease instanceof Lease) {
            return null;
        }

        return $lease->max_rent_payments !== null && $lease->max_rent_payments >= 1
            ? min($lease->max_rent_payments, self::MAX)
            : $this->organizationDefault();
    }
}
