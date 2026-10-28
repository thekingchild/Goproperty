<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

/**
 * One building's part of an owner's month (P08-05): its figures, rent and
 * utilities collected per unit, every amount collected or taken back, the
 * bills issued and the expenses charged to the owner.
 */
final readonly class BuildingStatement
{
    /**
     * @param  list<UnitLine>  $units
     * @param  list<CollectionLine>  $collections
     * @param  list<BillLine>  $bills
     * @param  list<ExpenseLine>  $expenses
     */
    public function __construct(
        public int $propertyId,
        public string $name,
        public bool $archived,
        public StatementFigures $figures,
        public array $units,
        public array $collections,
        public array $bills,
        public array $expenses,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'name' => $this->name,
            'archived' => $this->archived,
            'figures' => $this->figures->toArray(),
            'units' => array_map(static fn (UnitLine $line): array => $line->toArray(), $this->units),
            'collections' => array_map(static fn (CollectionLine $line): array => $line->toArray(), $this->collections),
            'bills' => array_map(static fn (BillLine $line): array => $line->toArray(), $this->bills),
            'expenses' => array_map(static fn (ExpenseLine $line): array => $line->toArray(), $this->expenses),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $figures = $data['figures'] ?? [];

        return new self(
            Snapshot::int($data, 'property_id'),
            Snapshot::text($data, 'name'),
            (bool) ($data['archived'] ?? false),
            StatementFigures::fromArray(is_array($figures) ? $figures : []),
            array_map(UnitLine::fromArray(...), Snapshot::rows($data, 'units')),
            array_map(CollectionLine::fromArray(...), Snapshot::rows($data, 'collections')),
            array_map(BillLine::fromArray(...), Snapshot::rows($data, 'bills')),
            array_map(ExpenseLine::fromArray(...), Snapshot::rows($data, 'expenses')),
        );
    }
}
