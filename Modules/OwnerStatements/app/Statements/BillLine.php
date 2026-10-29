<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** One bill issued in the month, with its status (then: a settled statement keeps it as settled) and the tenant as issued (name, phone, email since P03-16). */
final readonly class BillLine
{
    public function __construct(
        public string $number,
        public string $typeLabel,
        public string $unit,
        public string $tenantName,
        public CarbonImmutable $issuedAt,
        public Money $amount,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public ?string $tenantPhone = null,
        public ?string $tenantEmail = null,
    ) {}

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'type_label' => $this->typeLabel,
            'unit' => $this->unit,
            'tenant_name' => $this->tenantName,
            'issued_at' => $this->issuedAt->toIso8601String(),
            'amount' => $this->amount->kobo,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'status_color' => $this->statusColor,
            'tenant_phone' => $this->tenantPhone,
            'tenant_email' => $this->tenantEmail,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Snapshot::text($data, 'number'),
            Snapshot::text($data, 'type_label'),
            Snapshot::text($data, 'unit'),
            Snapshot::text($data, 'tenant_name'),
            Snapshot::date($data, 'issued_at'),
            Snapshot::money($data, 'amount'),
            Snapshot::text($data, 'status'),
            Snapshot::text($data, 'status_label'),
            Snapshot::text($data, 'status_color'),
            // Months settled before P03-16 recorded no contacts: they keep what they recorded.
            Snapshot::nullableText($data, 'tenant_phone'),
            Snapshot::nullableText($data, 'tenant_email'),
        );
    }
}
