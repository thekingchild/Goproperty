<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** One expense charged to the owner in the month. */
final readonly class ExpenseLine
{
    public function __construct(
        public CarbonImmutable $spentOn,
        public string $categoryLabel,
        public string $description,
        public string $unit,
        public Money $amount,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'spent_on' => $this->spentOn->toDateString(),
            'category_label' => $this->categoryLabel,
            'description' => $this->description,
            'unit' => $this->unit,
            'amount' => $this->amount->kobo,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Snapshot::date($data, 'spent_on'),
            Snapshot::text($data, 'category_label'),
            Snapshot::text($data, 'description'),
            Snapshot::text($data, 'unit'),
            Snapshot::money($data, 'amount'),
        );
    }
}
