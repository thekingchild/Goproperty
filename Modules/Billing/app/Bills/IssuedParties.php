<?php

declare(strict_types=1);

namespace Modules\Billing\Bills;

use Modules\Leasing\Contracts\BillingCycle;

/**
 * Who and what a bill is for, as printed on its invoice
 * (BRANDING_DOCUMENTS.md § 2 item 3: "Billed to" and "Property"): the
 * tenant's name, phone and email, the building, its address, the unit, and
 * the lease's number and cycle. Taken at issue by Bills\BillDetails and
 * kept in `bills.details`, so a later rename, an archived tenant or a
 * building handed over to another organization (P03-14) never changes an
 * issued invoice. Read back by key, never by key order (MySQL reorders
 * JSON keys).
 */
final readonly class IssuedParties
{
    public function __construct(
        public string $tenantName,
        public ?string $tenantPhone = null,
        public ?string $tenantEmail = null,
        public ?string $buildingName = null,
        public ?string $address = null,
        public ?string $unit = null,
        public ?string $leaseNumber = null,
        public ?BillingCycle $cycle = null,
    ) {}

    /**
     * For `bills.details`.
     *
     * @return array{tenant: array{name: string, phone: ?string, email: ?string}, building: array{name: ?string, address: ?string}, unit: ?string, lease: array{number: ?string, cycle: ?string}}
     */
    public function toArray(): array
    {
        return [
            'tenant' => ['name' => $this->tenantName, 'phone' => $this->tenantPhone, 'email' => $this->tenantEmail],
            'building' => ['name' => $this->buildingName, 'address' => $this->address],
            'unit' => $this->unit,
            'lease' => ['number' => $this->leaseNumber, 'cycle' => $this->cycle?->value],
        ];
    }

    /** @param  array<array-key, mixed>  $stored  toArray() as read back from the json column */
    public static function fromArray(array $stored): self
    {
        $tenant = is_array($stored['tenant'] ?? null) ? $stored['tenant'] : [];
        $building = is_array($stored['building'] ?? null) ? $stored['building'] : [];
        $lease = is_array($stored['lease'] ?? null) ? $stored['lease'] : [];
        $cycle = self::text($lease['cycle'] ?? null);

        return new self(
            tenantName: self::text($tenant['name'] ?? null) ?? '',
            tenantPhone: self::text($tenant['phone'] ?? null),
            tenantEmail: self::text($tenant['email'] ?? null),
            buildingName: self::text($building['name'] ?? null),
            address: self::text($building['address'] ?? null),
            unit: self::text($stored['unit'] ?? null),
            leaseNumber: self::text($lease['number'] ?? null),
            cycle: $cycle !== null ? BillingCycle::tryFrom($cycle) : null,
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
