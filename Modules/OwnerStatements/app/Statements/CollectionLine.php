<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One amount collected (or taken back) in the month: when it counted, why
 * (a payment confirmed, tenant credit used, a receipt voided), the bill it
 * was for (its number, type, unit and the tenant's name, phone and email
 * as issued: owners see them since P03-16) and the amount.
 */
final readonly class CollectionLine
{
    public function __construct(
        public CarbonImmutable $at,
        public string $kind,
        public string $kindLabel,
        public string $billNumber,
        public string $typeLabel,
        public bool $utility,
        public string $unit,
        public string $tenantName,
        public ?string $receiptNumber,
        public Money $amount,
        public ?string $tenantPhone = null,
        public ?string $tenantEmail = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'at' => $this->at->toIso8601String(),
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel,
            'bill_number' => $this->billNumber,
            'type_label' => $this->typeLabel,
            'utility' => $this->utility,
            'unit' => $this->unit,
            'tenant_name' => $this->tenantName,
            'receipt_number' => $this->receiptNumber,
            'amount' => $this->amount->kobo,
            'tenant_phone' => $this->tenantPhone,
            'tenant_email' => $this->tenantEmail,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Snapshot::date($data, 'at'),
            Snapshot::text($data, 'kind'),
            Snapshot::text($data, 'kind_label'),
            Snapshot::text($data, 'bill_number'),
            Snapshot::text($data, 'type_label'),
            (bool) ($data['utility'] ?? false),
            Snapshot::text($data, 'unit'),
            Snapshot::text($data, 'tenant_name'),
            Snapshot::nullableText($data, 'receipt_number'),
            Snapshot::money($data, 'amount'),
            // Months settled before P03-16 recorded no contacts: they keep what they recorded.
            Snapshot::nullableText($data, 'tenant_phone'),
            Snapshot::nullableText($data, 'tenant_email'),
        );
    }
}
