<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;

/**
 * Money billed and collected, by kind of bill (P08-01): rent, utilities,
 * and other (opening balances and other bills), with the totals and the
 * collection rate.
 */
final readonly class FlowTotals
{
    /** The kinds of bills, in the order they are shown, and their labels. */
    public const array GROUPS = ['rent' => 'Rent', 'utility' => 'Utilities', 'other' => 'Other (opening balances, other bills)'];

    /**
     * @param  array<string, Money>  $billed  group => amount
     * @param  array<string, Money>  $collected  group => amount
     */
    public function __construct(
        public array $billed,
        public array $collected,
    ) {}

    public static function empty(): self
    {
        $zeros = array_map(static fn (): Money => Money::zero(), self::GROUPS);

        return new self($zeros, $zeros);
    }

    /** The group a `bills.type` value counts in. */
    public static function groupOf(string $billType): string
    {
        return match ($billType) {
            'rent' => 'rent',
            'utility' => 'utility',
            default => 'other',
        };
    }

    public function addBilled(string $group, Money $amount): self
    {
        return new self([...$this->billed, $group => $this->billed[$group]->plus($amount)], $this->collected);
    }

    public function addCollected(string $group, Money $amount): self
    {
        return new self($this->billed, [...$this->collected, $group => $this->collected[$group]->plus($amount)]);
    }

    public function plus(self $other): self
    {
        $billed = $this->billed;
        $collected = $this->collected;

        foreach (array_keys(self::GROUPS) as $group) {
            $billed[$group] = $billed[$group]->plus($other->billed[$group]);
            $collected[$group] = $collected[$group]->plus($other->collected[$group]);
        }

        return new self($billed, $collected);
    }

    public function billedTotal(): Money
    {
        return Money::sum($this->billed);
    }

    public function collectedTotal(): Money
    {
        return Money::sum($this->collected);
    }

    public function rate(): ?Rate
    {
        return Rate::of($this->collectedTotal(), $this->billedTotal());
    }

    public function rateOf(string $group): ?Rate
    {
        return Rate::of($this->collected[$group], $this->billed[$group]);
    }
}
