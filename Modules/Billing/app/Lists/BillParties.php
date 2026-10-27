<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use Modules\Billing\Bills\IssuedParties;
use Modules\Billing\Models\Bill;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The tenant, unit and building names of a batch of bills, read through
 * the Tenants and Properties contracts with one lookup each per batch (no
 * query per bill, ARCHITECTURE.md § 13), never by joining their tables
 * (§ 8 rule 3). A name the contracts no longer give (the tenant, unit or
 * building is gone from this organization, e.g. handed over, P03-14) falls
 * back to the name kept on the bill at issue (`bills.details`, P03-05c).
 */
final readonly class BillParties
{
    public function __construct(
        private TenantDirectory $tenants,
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
    ) {}

    /**
     * @param  iterable<Bill>  $bills
     * @return list<BillRow>
     */
    public function rows(iterable $bills): array
    {
        $bills = is_array($bills) ? array_values($bills) : iterator_to_array($bills, false);

        if ($bills === []) {
            return [];
        }

        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (Bill $bill): int => $bill->tenant_profile_id, $bills))));
        $units = $this->units->findMany(array_values(array_unique(array_filter(array_map(static fn (Bill $bill): ?int => $bill->unit_id, $bills)))));
        $buildings = $this->buildings->findMany(array_values(array_unique(array_filter(array_map(static fn (Bill $bill): ?int => $bill->property_id, $bills)))));

        $rows = [];

        foreach ($bills as $bill) {
            $unitLabel = null;
            $buildingName = null;
            $issued = is_array($bill->details) ? IssuedParties::fromArray($bill->details) : null;

            if ($bill->unit_id !== null) {
                $unitLabel = isset($units[$bill->unit_id]) ? $units[$bill->unit_id]->label : (($issued !== null ? $issued->unit : null) ?? self::text('Archived unit'));
            }

            if ($bill->property_id !== null) {
                $buildingName = isset($buildings[$bill->property_id]) ? $buildings[$bill->property_id]->name : (($issued !== null ? $issued->buildingName : null) ?? self::text('Removed building'));
            }

            $issuedTenant = $issued !== null && $issued->tenantName !== '' ? $issued->tenantName : null;

            $rows[] = new BillRow(
                bill: $bill,
                tenantName: isset($tenants[$bill->tenant_profile_id]) ? $tenants[$bill->tenant_profile_id]->name : ($issuedTenant ?? self::text('Unknown tenant')),
                unitLabel: $unitLabel,
                buildingName: $buildingName,
            );
        }

        return $rows;
    }

    /** A translated fallback label (translations of these keys are plain strings). */
    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
