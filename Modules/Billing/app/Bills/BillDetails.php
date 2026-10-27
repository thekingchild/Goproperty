<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillItem;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The parties of a bill (IssuedParties): taken at issue by
 * Bills\BillIssuer through the Tenants and Properties contracts (never
 * their tables, ARCHITECTURE.md § 8 rule 3) and kept on the bill
 * (`bills.details`); read back for the invoice, the bill page and the
 * lists. A bill issued before parties were kept (P03-05c) is read as the
 * parties are now.
 */
final readonly class BillDetails
{
    public function __construct(
        private TenantDirectory $tenants,
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
    ) {}

    /** The parties as they are now, in the current organization. */
    public function take(int $tenantProfileId, ?int $propertyId, ?int $unitId, ?string $leaseNumber, ?BillingCycle $cycle): IssuedParties
    {
        $tenant = $this->tenants->find($tenantProfileId);
        $unit = $unitId !== null ? $this->units->find($unitId) : null;
        $building = $propertyId !== null ? $this->buildings->find($propertyId) : null;

        return new IssuedParties(
            tenantName: $tenant !== null ? $tenant->name : self::text('Unknown tenant'),
            tenantPhone: $tenant !== null && $tenant->phone !== '' ? $tenant->phone : null,
            tenantEmail: $tenant?->email,
            buildingName: $building !== null ? $building->name : $unit?->propertyName,
            address: $building !== null ? self::address($building->address, $building->state) : null,
            unit: $unit?->label,
            leaseNumber: $leaseNumber,
            cycle: $cycle,
        );
    }

    /** The bill's parties as issued (or, for a bill issued before they were kept, as they are now). */
    public function of(Bill $bill): IssuedParties
    {
        if (is_array($bill->details)) {
            return IssuedParties::fromArray($bill->details);
        }

        $meta = BillItem::query()->where('bill_id', $bill->id)->orderBy('id')->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $meta = is_array($meta) ? $meta : [];
        $number = $meta['contract_number'] ?? null;
        $cycle = $meta['cycle'] ?? null;

        return $this->take(
            $bill->tenant_profile_id,
            $bill->property_id,
            $bill->unit_id,
            is_string($number) ? $number : null,
            is_string($cycle) ? BillingCycle::tryFrom($cycle) : null,
        );
    }

    /** "22 Fola Osibo Road, Lekki Phase 1, Lagos": the state added unless the address names it. */
    private static function address(string $address, string $state): ?string
    {
        $address = trim($address);
        $state = trim($state);
        $line = $state === '' || str_contains(mb_strtolower($address), mb_strtolower($state)) ? $address : ($address === '' ? $state : $address.', '.$state);

        return $line === '' ? null : $line;
    }

    /** A translated fallback label (translations of these keys are plain strings). */
    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
