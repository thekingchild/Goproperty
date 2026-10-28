<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;

/**
 * Rent and utilities collected for one unit in the month (BRANDING_DOCUMENTS.md
 * § 4: "rent collected per unit"). The unit is named as on its bills.
 */
final readonly class UnitLine
{
    public function __construct(
        public string $unit,
        public Money $rent,
        public Money $utilities,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['unit' => $this->unit, 'rent' => $this->rent->kobo, 'utilities' => $this->utilities->kobo];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(Snapshot::text($data, 'unit'), Snapshot::money($data, 'rent'), Snapshot::money($data, 'utilities'));
    }
}
